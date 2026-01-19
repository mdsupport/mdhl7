<?php
/**
 * Get Z34 – Request Complete Immunization History from CAlifornia Immunization Registry
 *
 * Copyright (C) 2025-2026 MD Support <mdsupport@users.sourceforge.net>
 *
 * @package   Mdhl7
 * @author    MD Support <mdsupport@users.sourceforge.net>
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/CAIRsoap.php';

use Aranyasen\HL7\Message;
use Aranyasen\HL7\Messages\ACK;
use Aranyasen\HL7\Segments\MSH;
use Aranyasen\HL7\Segments\PID;
use Aranyasen\HL7\Segments\PD1;
use Aranyasen\HL7\Segments\ORC;
use Aranyasen\HL7\Segments\RXA;
use Aranyasen\HL7\Segments\OBX;
use Aranyasen\HL7\Segments\RXR;
use Dotenv\Dotenv;
use Mdsupport\Mdpub\DevObj\DevDB;

define('NAME', 'OpenEMR-VXU');
define('VERSION', '.2');

function record_result($hl7, $hl7ResponseString, $id, $env)
{
    if (!$hl7ResponseString || (strlen($hl7ResponseString) < 1)) {
        $hl7ResponseString = '';
        $ackCode = '';
    } else {
        $objHL7Response = new Message($hl7ResponseString);
        $objMSA = $objHL7Response->getSegmentsByName('MSA')[0];
        $ackCode = $objMSA->getAcknowledgementCode();
    }
    
    // Row to be inserted
    $aaRec = [
        'msg_type' => 'VXU',
        'msg_partner' => $env['if.IR.wsdl'],
        'link_source' => 'immunizations',
        'link_key' => $id,
        'msg_body' => $hl7,
        'msg_response' => $hl7ResponseString,
        'msg_result' => $ackCode,
    ];
    
    $dbResult = $GLOBALS['adodb']['db']->autoExecute('hl7log', $aaRec, 'INSERT');
    return $dbResult;
}

/*
 * This script uses hl7log table in the database to track what has been
 * sent and received to IR.
 */
function db_init($connSrc)
{
    $connKeys = preg_grep("/^if\.OpenEMR\.db/",array_keys($connSrc));
    if (count($connKeys) < 4) {
        echo "{$connSrc['_dotenv']}/dotenv/mdhl7.env missing or without database connection entries.";
        return false;
    }
    $adoConn = adoNewConnection($connSrc['if.OpenEMR.dbdriver'] ?? 'mysqli');
    $boolConnected = $adoConn->connect(
        $connSrc['if.OpenEMR.dbhost'],
        $connSrc['if.OpenEMR.dbuser'],
        $connSrc['if.OpenEMR.dbuser.password'],
        $connSrc['if.OpenEMR.dbname']
        );
    if ($boolConnected) {
        $GLOBALS['adodb']['db'] = $adoConn;
        echo "Using {$connSrc['if.OpenEMR.dbname']} on {$connSrc['if.OpenEMR.dbhost']}" . PHP_EOL;
    } else {
        echo "Could not connect to {$connSrc['if.OpenEMR.dbname']} on {$connSrc['if.OpenEMR.dbhost']}.";
        return false;
    }
    
    $adoEMR = new DevDB();
    $checkDB = $adoConn->metaColumnNames('hl7log');
    if (!$checkDB) {
        echo 'No hl7log table. VXU setup incomplete?'.PHP_EOL;
        return false;
    }

    return $adoEMR;
}

function mxu_init() {
    if ($stream = fopen('https://www2a.cdc.gov/vaccines/iis/iisstandards/downloads/mvx.txt', 'r')) {
        // print all the page starting at the offset 10
        echo stream_get_contents($stream);
        
        fclose($stream);
    }
}

// Try getting connection parameters from cached $_SESSION
// For CLI scripts cache will not be available.
$env = [];
if (php_sapi_name() == 'cli') {
    $env = &$_ENV;
    $env['_dotenv'] = getenv("HOME");
} else {
    $env = &$_SESSION;
    $env['_dotenv'] = $_SERVER["HOME"];
}

$dotenv = Dotenv::createImmutable("{$env['_dotenv']}/dotenv", 'mdhl7.env');
$dotenv->load();

$objDB = db_init($env);
if (!$objDB) {
    echo 'Database connection failed.';
    exit();
}

openlog(NAME . ': ' . VERSION, LOG_PID | LOG_PERROR, LOG_USER);

$cairSOAP = new CAIRsoap();
try {
    $cairSOAP->setFromGlobals([
        'username' => $env['if.IR.user'],
        'password' => $env['if.IR.password'],
        'facility' => $env['if.IR.facility'],
        'wsdl_url' => __DIR__ . '/'. $env['if.IR.wsdl']
    ])
    ->initializeSoapClient();
}catch (Exception $e) {
    echo $e->getMessage();
    
    exit;
}

/* Complex SQL -
 * SELECT immunizations records
 * - must match patient
 * - must have had lot number in inventory (TBD - include manufacturer if lots are not unique)
 * - must not have prior transmissions (may need revision if muliple targets exist)
 * - optionally match ordering provider
 * - optionally match administrator of this immunization
 * - optionally match UoM for administered quantity
 */
$aaExec = [
    'sql' => "
        SELECT imm.*, md.npi md_npi, md.title md_title, md.fname md_fname, md.lname md_lname, IFNULL(du.title,'') dose_units,
            DATE_FORMAT(imm.administered_date,'%Y%m%d') as administered_Ymd,
            cl.fname cl_fname, cl.lname cl_lname, IFNULL(cl.title, 'MA') cl_title,
            mxu.code mxu_code
        FROM prd_immunizations imm
        INNER JOIN (
    	 	SELECT cd.code, cd.code_text manufacturer FROM codes cd
    		INNER JOIN code_types ct ON ct.ct_id=cd.code_type
    		WHERE ct.ct_key='MXU') mxu ON mxu.manufacturer = imm.manufacturer
        INNER JOIN (
            SELECT DISTINCT lot_number FROM prd_drug_inventory) lots ON lots.lot_number = imm.lot_number
        INNER JOIN prd_patient_data pt ON pt.pid = imm.patient_id
        LEFT JOIN hl7log IR ON IR.msg_type='VXU' AND IR.link_source='immunizations' AND imm.id = IR.link_key
        LEFT JOIN prd_users md ON md.id = IFNULL(imm.ordering_provider,pt.providerID)
        LEFT JOIN prd_users cl ON cl.id = IFNULL(imm.administered_by_id,pt.providerID)
        LEFT JOIN list_options du ON du.list_id='drug_units' AND du.option_id=imm.amount_administered_unit
        WHERE IR.link_key IS NULL
        AND LENGTH(imm.cvx_code) > 1
        AND TRIM(IFNULL(imm.lot_number, '')) <> ''
        AND TRIM(IFNULL(pt.fname, '')) <> ''
        AND TRIM(IFNULL(pt.lname, '')) <> ''
        AND pt.DOB IS NOT NULL
        ORDER BY imm.administered_date desc
        LIMIT 100
    ",
    'bind' => [],
    'sfx' => '',
    'return' => 'array',
    'debug' => false,
];

$rsVxu = $objDB->execSql($aaExec);

$ackCount = new stdClass();
$nowdate = date('Ymd');

$rsPt = $objDB->execSql([
        'sql' => "select * from prd_patient_data where pid = ?",
        'bind' => [26118],
        'sfx' => "limit 1",
        'return' => 'array',
        'debug' => false,
    ]);
    $patient = $rsPt[0];
    
    // GENERATE HL7 MESSAGE USING SEGMENT OBJECTS
    
    // Create MSH segment
    $objMSH = new MSH();
    $objMSH->setSendingApplication('OPENEMR');
    $objMSH->setSendingFacility($env['if.IR.facility']);
    $objMSH->setReceivingFacility($env['if.IR.region']);
    $objMSH->setDateTimeOfMessage(date('YmdHis') . "+0000");
    $objMSH->setMessageType('QBP^Q11^QBP_Q11');
    $objMSH->setMessageControlId(date('Ymdhis') . $vxu['cvx_code']. $vxu['id'] );
    $objMSH->setProcessingId('P');
    $objMSH->setVersionId('2.5.1');
    $objMSH->setField(15, 'ER'); // Accept Acknowledgment Type
    $objMSH->setField(16, 'AL'); // Application Acknowledgment Type
//    $objMSH->setField(21, ''); // Message Profile Identifier
//    $objMSH->setField(22, $env['if.IR.region']); // Sending Responsible Organization
    
    // Create QPD segment
    $objQPD = new Segment("QPD");  ;
    $objQPD->setField(1, "Z34^Request Complete Immunization History^HL70471"); ;

    $strNameAZ = sprintf(
        '%s^%s^^^^^L',
        preg_replace('/[^a-z]/i', ' ', $patient['lname']),
        preg_replace('/[^a-z]/i', ' ', $patient['fname'])
    );
    $objQPD->setField(4, $strNameAZ);
    // Date of birth
    $objQPD->setField(6, preg_replace('/-/', '', $patient['DOB']));
    
    // Create the message
    $objMessage = new Message();
    $objMessage->addSegment($objMSH);
    $objMessage->addSegment($objQPD);
    
    // Convert message to string
    $hl7 = $objMessage->toString(true); // true for pretty print
    
//    print($hl7);
    
    try{
        $cairResponseVXU = $cairSOAP->submitSingleMessage($hl7);
    }catch(Exception $e){
        syslog(LOG_ERR, 'Unable to submit VXU to IR: ' . $e->getMessage());
    }
    
    if (property_exists($cairResponseVXU, 'return')) {
        $ackCAIR = new Message($cairResponseVXU->return);
        $ackMSA = $ackCAIR->getFirstSegmentInstance('MSA');
        $ackCode = $ackMSA->getField(1);
        $ackCount->$ackCode =  (property_exists($ackCount, $ackCode) ? $ackCount->$ackCode : 0) + 1;
        if ($ackCode != 'AA') {
            // Walk thru ERR segments
            $ackERRs = $ackCAIR->getSegmentsByName('ERR');
            foreach ($ackERRs as $ackERR) {                
                syslog(LOG_ERR, $ackERR->getField(8));
            }
        }
    }
    var_dump($cairResponseVXU->return);
    exit();
    /*
    $cairResponseVXU = $cairResponseVXU->return;
    if (strpos($cairResponseVXU, 'MSH') === false) {
        syslog(LOG_ERR, "Failed to send HL7");
    }
    */
    record_result($hl7, $cairResponseVXU->return, $vxu['id'], $env);

// Log summary counts
foreach ($ackCount as $ackCode => $acks) {
    if ($ackCode == 'AA') {
        $ackCode = 'Accepted';
    } elseif ($ackCode == 'AR') {
        $ackCode = 'Rejected';
    } elseif ($ackCode == 'AE') {
        $ackCode = 'Accepted* (See Messages)';
    }
    syslog(LOG_ERR, sprintf('%s: %s', $ackCode, $acks));
}
closelog();