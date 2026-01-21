<?php
/**
 * Fetch hl7 lab results
 *
 * Copyright (C) 2025-2026 MD Support <mdsupport@users.sourceforge.net>
 *
 * @package   Mdhl7
 * @author    MD Support <mdsupport@users.sourceforge.net>
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require dirname(__FILE__, 3) . '/vendor/autoload.php';

use Aranyasen\HL7\Message;
use Aranyasen\HL7\Messages\ACK;
use Aranyasen\HL7\Segments\MSH;
use Aranyasen\HL7\Segments\PID;
use Dotenv\Dotenv;
use Mdsupport\Mdpub\DevObj\DevDB;
use phpseclib3\Net\SFTP;

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
        echo 'No hl7log table. Setup incomplete?'.PHP_EOL;
        return false;
    }

    return $adoEMR;
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

// Ensure that archive directory exists.
$prpath = $env['if.dir.orders.os'];
if (!file_exists($prpath)) {
    if (!mkdir($prpath, 0755, true) && !is_dir($prpath)) {
        throw new RuntimeException(sprintf('Directory "%s" was not created', $prpath));
    }
}

/* Process SFTP connections */
$aaExec = [
    'sql' => "SELECT * FROM procedure_providers conn",
    'where' => [ 'protocol' => 'SFTP', 'active' => 1 ],
    'sfx' => '',
    'return' => 'array',
    'debug' => false,
];

$rsConn = $objDB->execSql($aaExec);

$fileCount = [];
$nowdate = date('YmdHis');
foreach ($rsConn as $conn) {
    printf('Connecting to %s at %s%s', $conn['name'], $conn['remote_host'], PHP_EOL);
    if (!isset($fileCount[$conn['name']])) {
        $fileCount[$conn['name']] = 0;
    }
    // $remote_host = explode(':', $conn['remote_host']);
    // Connect to the server and enumerate files to process.
    $connActive = new SFTP($conn['remote_host']);
    if (!$connActive->login($conn['login'], $conn['password'])) {
        printf('Login to %s as %s failed.', $conn['remote_host'], $conn['login']);
        continue;
    }
    
    $connPath = trim($conn['results_path']??'');
    $connPath .= ($connPath == '' ? '.' : '/.');
    $aResults = $connActive->nlist($connPath);
  
    foreach ($aResults as $nlFile) {
        if (str_starts_with((string)$nlFile, '.')) {
            continue;
        }
        // Rename file to avoid duplicate issues.  Store this name in hl7log to keep it portable.
        $archFname = "$nowdate.{$conn['ppid']}.{$conn['npi']}.$nlFile";
        $archFpath = "{$env['if.dir.orders.os']}/$archFname";
        $connActive->get("$connPath/$nlFile", $archFpath);

        // Let's see what we got
        $fContents = file_get_contents($archFpath, false);
        $objMsg = new Message($fContents);
        $isValid = false;
        foreach ($objMsg->getSegmentsByName('PID') as $segPID) {
            $isValid = true;
            list($lname, $fname) = $segPID->getPatientName();
            $dob = substr($segPID->getDateTimeOfBirth(), 0, 8);
            $aaExec = [
                'sql' => "SELECT * FROM patient_data",
                'where' => [ 
                    'LCASE(lname)' => strtolower($lname), 
                    'LCASE(fname)' => strtolower($fname),
                    'DOB' => $dob ],
                'sfx' => '',
                'return' => 'array',
                'debug' => false,
            ];
            $aPts = $objDB->execSql($aaExec);

            // Create entry in hl7log
            // HL7 permits One transmission to have muliple patient records
            // Row to be inserted
            $aaRec = [
                'msg_type' => 'ORD',
                'msg_partner' => json_encode([$conn['ppid'] => $conn['name']]),
                'link_source' => 'procedure_orders',
                'link_key' => (count($aPts) == 1 ? $aPts[0]['pid'] : 0),
                'msg_body' => json_encode([
                    'file' => $archFname,
                    'lname' => $lname,
                    'fname' => $fname,
                    'DOB' => $dob,
                ]),
                'msg_response' => '',
                'msg_result' => (count($aPts) == 1 ? 'PT' : 'CHK'),
            ];
            
            $dbResult = $objDB->adoMethod([
                'autoExecute' => ['hl7log', $aaRec, 'INSERT']
            ]);
        }

        // Delete file from provider site to avoid duplicate transmissions 
        // $connActive->delete("$connPath/$strFile");

        $fileCount[$conn['name']]++;
    };
    
}
// Log summary counts
foreach ($fileCount as $connName => $connFiles) {
    printf('%s => %s files.%s', $connName, $connFiles, PHP_EOL );
}
closelog();