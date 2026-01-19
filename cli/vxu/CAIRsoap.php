<?php

/**
 * Functions for interacting with CAIR SOAP calls.
 *
 * Copyright (C) 2015-2019 Daniel Pflieger <daniel@mi-squared.com> <daniel@growlingflea.com>
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 3 of the License, or (at your option) any
 * later version.  This program is distributed in the hope that it will be
 * useful, but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General
 * Public License for more details.  You should have received a copy of the GNU
 * General Public License along with this program.
 * If not, see <http://opensource.org/licenses/gpl-license.php>.
 *
 * @author     Daniel Pflieger daniel@mi-squared.com>
 * @link       http://www.open-emr.org
 *
 * 2016-04-05 Changes made to keep this compliant with CAIR's requirement for TLS1.2
 * 2019-06-27 Changes made for 2 way communiation
 */


require_once("$srcdir/sql.inc");
require_once("$srcdir/patient.inc");
require_once("$srcdir/formatting.inc.php");

class CAIRsoap
{

    private $Username;
    private $Password;
    private $Facility;
    private $wsdlUrl;
    private $certs;

    private $soapClient;


    public function setUsername($username)
    {
        $this->Username = $username;

        return $this;
    }

    public function getUsername()
    {
        return $this->Username;
    }

    public function setPassword($password)
    {
        $this->Password = $password;

        return $this;
    }

    public function getPassword()
    {
        return $this->Password;
    }

    public function setFacility($facility)
    {
        $this->Facility = $facility;

        return $this;
    }

    public function getFacility()
    {
        return $this->Facility;
    }

    public function setCerts($certs)
    {
        $this->certs = $certs;
        return $this;
    }

    private function get_string_between($string, $start, $end)
    {
        $string = ' ' . $string;
        $ini = strpos($string, $start);
        if ($ini == 0) return '';
        $ini += strlen($start);
        $len = strpos($string, $end, $ini) - $ini;
        return substr($string, $ini, $len);
    }

    private static function tr($a)
    {
        return (str_replace(' ', '^', $a));
    }

    private static function format_cvx_code($cvx_code)
    {

        if ($cvx_code < 10) {
            return "0$cvx_code";
        }

        return $cvx_code;
    }

    private static function format_phone($phone)
    {

        $phone = preg_replace("/[^0-9]/", "", $phone);
        switch (strlen($phone)) {
            case 7:
                return self::tr(preg_replace("/([0-9]{3})([0-9]{4})/", "000 $1$2", $phone));
            case 10:
                return self::tr(preg_replace("/([0-9]{3})([0-9]{3})([0-9]{4})/", "$1 $2$3", $phone));
            default:
                return self::tr("000 0000000");
        }
    }

    private static function format_ethnicity($ethnicity)
    {

        switch ($ethnicity) {
            case "hisp_or_latin":
                return ("H^Hispanic or Latino^HL70189");
            case "not_hisp_or_latin":
                return ("N^not Hispanic or Latino^HL70189");
            default: // Unknown
                return ("U^Unknown^HL70189");
        }
    }


    private function getErrorsArray($responseArray)
    {

        $errorArray = array();
        foreach ($responseArray as $resp) {

            if (strpos($resp, 'Informational Error') === false) {
                continue;
            }
            $resp = explode('ERR', $resp);
            $resp = explode('Informational Error', $resp[0]);
            $errorArray[] = substr(trim($resp[1]), 2);
        }

        return $errorArray;
    }

    private function duplicateImmunizationCheck($id)
    {

        $query = sqlQuery("select * from immunization_log where immunization_id = ?", array($id));

        return $query;


    }


    public function getCerts()
    {
        return $this->certs;
    }

    public function setWsdlUrl($wsdlUrl)
    {
        $this->wsdlUrl = $wsdlUrl;

        return $this;
    }

    public function getWsdlUrl()
    {
        return $this->wsdlUrl;
    }

    public function displayTargetWdsl()
    {

        $wdsl = $this->getWsdlUrl();

        if (strpos($wdsl, 'CATRN') !== false) {

            return "Sending to CAIR TRAINING";

        } else if (strpos($wdsl, 'CAPRD') !== false) {

            return "Sending to CAIR PRODUCTION";

        } else {

            return "WDSL not recognized.  Errors may happen";
        }
    }

    public function setFromGlobals($globalsArray)
    {
        $this->setUsername($globalsArray['username']);
        $this->setPassword($globalsArray['password']);
        $this->setFacility($globalsArray['facility']);
        $this->setWsdlUrl($globalsArray['wsdl_url']);


        return $this;
    }

    public function setSoapClient(SoapClient $client)
    {
        $this->soapClient = $client;

        return $this;
    }

    public function getSoapClient()
    {
        try {
            return $this->soapClient;

        } catch (Exception $e) {

            echo $e->getMessage() . " No Communication to CAIR available.";

        }
    }

    public function initializeSoapClient()
    {
        $opts = array(

            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        $context = stream_context_create($opts);


        try {
            return $this->setSoapClient(new SoapClient($this->getWsdlUrl(), array('stream_context' => $context,
                'cache_wsdl' => WSDL_CACHE_NONE, 'soap_version' => SOAP_1_2)));
        } catch (Exception $e) {

            echo $e->getMessage() . " No Communication to CAIR available.";
        }
    }

    public function getNDC11($ndc)
    {

        $sql = "select NDC11, description from immunizations_schedules_codes where GTIN like '%$ndc%' ";
        $res = sqlQuery($sql);
        return $res;

    }

    public function submitSingleMessage($message)
    {

        //submitSingleMessage connectivityTest
        try {

            return $this->getSoapClient()->submitSingleMessage(
                array(
                    'username' => $this->getUsername(),
                    'password' => $this->getPassword(),
                    'facilityID' => $this->getFacility(),
                    'hl7Message' => $message
                )
            );
        } catch (Exception $e) {
            echo $e->getMessage();
            exit;
        }
    }


    public static function getCurrentImmStatus($immID)
    {

        $sql = "Select count(*) as count from immunization_log where received_message like '%|AA|%' " .
            " AND immunization_id = $immID ";

        $count = sqlQuery($sql);

        return $count['count'];
    }

    public static function getNumAttempts($immID)
    {

        $sql = "Select count(*) as count from immunization_log where immunization_id = $immID ";

        $count = sqlQuery($sql);

        return $count['count'];
    }

    public static function getNumFailures($immID)
    {

        $sql = "Select count(*) as count from immunization_log where received_message not like '%|AA|%' " .
            " AND immunization_id = $immID ";

        $count = sqlQuery($sql);

        return $count['count'];
    }

    //********************************************************************************************************
    //This part of the code is the meat of the message creation and decoding.  There are four main high level
    //types of messages: VXU (vaccine submission), ACK (acknowledgement of vaccine submission), QBP(query by parameter)
    //and RSP (response to QBP).
    //MSH is the Message Segment Header.  All messages start with this.
    //The other segments are shared within types of high level messages.  For example, VXU and RSP contain much of the
    //similar segments but only differ in the direction they head - to CAIR or from CAIR
    //This function takes in an HL7 response and converts it to an array so
    //it can be read by OpenEMR.  This is the response to the vaccine submission

    //This generates the HL7 vaccination message that we submit to CAIR.
    //The required outputs are the $pid and the immunization_id.
    //The message is called VXU.  It is composed of the MSH, PID, PD1, NK1, ORC, RXA, RXR and OBX segments
    public static function gen_HL7_VXU($pid, $imm_id)
    {

        $out = '';

        $out .= self::genHL7_MSH($pid, 'submit', $imm_id);
        $out .= self::genHL7_PID($pid, $imm_id);
        $out .= self::genHL7_PD1($pid, $imm_id);
        $out .= self::genHL7_ORC(); //This field is required but is blank at the moment
        $out .= self::genHL7_RXA($imm_id);

        return $out;

    }

    //This generates the HL7 Query By Parameter HL7 message.
    //This is the message we send to query either the immunization history or the immunization predictions
    //for a specific patient.  The segments are: MSH, QPD, RCP
    public static function gen_HL7_CAIR_QBP($pid, $type = 'query')
    {
        //new lines at the end of each segment are required for the X_to_array functions.
        $imm_id = '';
        $out = '';
        $out .= self::genHL7_MSH($pid, $type) . "\n";
        $out .= self::genHL7_QPD($pid, $type) . "\n";
        $out .= self::genHL7_RCP(50);

        return $out;
    }

    //This converts the entire ACK message to an object.
    //The segments include MSH, MSA, [ERR]
    //This is the message returned to a VXU message that has been SENT to CAIR
    static public function ACK_to_array($message)
    {

        $out = array();

        $out['MSH'] = array();
        $out['MSA'] = array();
        $out['ERR'] = array();


        $message = explode(PHP_EOL, $message);
        $delimiter = "|";

        foreach ($message as $segment) {

            $data = explode($delimiter, $segment);
            //MSH Message Header
            if ($data[0] === "MSH") {

                $out['MSH'] = self::MSH_to_array($data);

            } else if ($data[0] === "MSA") {

                $out['MSA'] = self::MSA_to_array($data);

            } else if ($data[0] == "ERR") {
                //this is going to be a array

                $error = self::ERR_to_array($data);

                array_push($out['ERR'], $error);

            }
        }

        return $out;

    }

    static public function getCairID($pid)
    {

        $sql = "Select CAIR from patient_data where pid = $pid";
        $res = sqlQuery($sql);
        return $res['CAIR'];


    }

    static public function print_message($message)
    {
        $out = '';
        $message = explode(PHP_EOL, $message);
        foreach ($message as $segment) {

            $out .= $segment . " <br>";

        }

        return $out;
    }

    //This takes the response the the QBP and turns it into an array so we can display and work with the data.
    //This object helps us
    //The segments that a RSP message are composed of are MSH, MSA, [ERR], QAK, QPD[], PID,
    //[PD1], NK1, ORC, RXA, [RXR], OBX
    static public function RSP_to_array($message)
    {

        $out = array();
        $obx_index = 0;
        $out['MSH'] = array(); //Message Header
        $out['MSA'] = array(); //Message ACK
        $out['ERR'] = array(); //Error Segment
        $out['QAK'] = array(); //Query ACK
        $out['QPD'] = array(); //Query Parameter Definition - this is a response group
        $out['PID'] = array(); //Patient ID
        $out['PD1'] = array(); //Patient Demographic Info
        $out['NK1'] = array(); //Next of Kin
        $out['ORC'] = array(); //Order Request
        $out['RXA'] = array(); //Pharamcy Treatment
        $out['RXR'] = array(); //Pharamcy treatment route
        $out['OBX'] = array(); //Observation[]

        $message = explode(PHP_EOL, $message);
        $delimiter = "|";

        foreach ($message as $segment) {

            $data = explode($delimiter, $segment);
            //MSH Message Header
            if ($data[0] === "MSH") {

                $out['MSH'] = self::MSH_to_array($data);

            } else if ($data[0] === "MSA") {

                $out['MSA'] = self::MSA_to_array($data);

            } else if ($data[0] == "ERR") {
                //this is going to be a array

                $error = self::ERR_to_array($data);

                array_push($out['ERR'], $error);

            } else if ($data[0] == "QAK") {

                $out['QAK'] = self::QAK_to_array($data);

            } else if ($data[0] == "QPD") {

                $out['QPD'] = self::QPD_to_array($data);

            } else if ($data[0] == "PID") {

                $out['PID'] = self::PID_to_array($data);

            } else if ($data[0] == "PD1") {

                $out['PD1'] = self::PD1_to_array($data);

            } else if ($data[0] == "NK1") {
                continue;
                $out['NK1'] = self::NK1_to_array($data);

            } else if ($data[0] == "QAK") {

                $out['QAK'] = self::QAK_to_array($data);

            } else if ($data[0] == "ORC") {

                $orc = self::ORC_to_array($data);
                array_push($out['ORC'], $orc);

            } else if ($data[0] == "RXA") {

                $rxa = self::RXA_to_array($data);
                array_push($out['RXA'], $rxa);
                $obx_index = sizeof($out['RXA']) - 1;
                $out['OBX'][$obx_index] = array();
                $out['RXR'][$obx_index] = array();
            } else if ($data[0] == "RXR") {


                $rxr = self::RXR_to_array($data);
                array_push($out['RXR'][$obx_index], $rxr);

            } else if ($data[0] == "OBX") {

                $obx = self::OBX_to_array($data);
                array_push($out['OBX'][$obx_index], $obx);
            }

        }


        return $out;
    }

    //This gets the newest IMMID so we can sent the new vaccine to CAIR
    public static function getNewImmID($pid)
    {

        $sql = "select max(id) as id from immunizations where patient_id  = $pid";
        $res = sqlQuery($sql);
        return $res['id'];

    }
    //The lower level function are here

    //This generates the Message Header Segment (MSH).  This is required for each
    // immunization submission or query.  This is used for both requests
    private static function genHL7_MSH($pid, $type = 'submit', $imm_id = '')
    {

        //***HARD CODE - These values are taken from the CAIR manual
        if ($type == 'query') {
            $sql = "select * from patient_data where pid = $pid";
            $r['immunizationtitle'] = "QBP_request" . time();
            $IMM_message_type = 'QBP^Q11^QBP_Q11';
            $IMM_accept_ack_type = 'ER';
            $IMM_message_profile_ID = 'Z34^CDCPHINVS';

        } else if ($type == 'projection') {
            $sql = "select * from patient_data where pid = $pid";
            $r['immunizationtitle'] = "QBP_request" . time();
            $IMM_message_type = 'QBP^Q11^QBP_Q11';
            $IMM_accept_ack_type = 'ER';
            $IMM_message_profile_ID = 'Z44^CDCPHINVS';

        } else {
            $sql = "Select *, DATE_FORMAT(i.vis_date,'%Y%m%d') as immunizationdate, c.code_text_short as immunizationtitle, " .
                "DATE_FORMAT(i.administered_date,'%Y%m%d') as administered_date  " .
                " from immunizations i join codes c on i.cvx_code = c.code " .
                "join code_types ct on c.code_type = ct.ct_id " .
                "where ct.ct_key='CVX' and i.id = $imm_id";
            $IMM_message_type = 'VXU^V04^VXU_V04';
            $IMM_accept_ack_type = 'NE';
            $IMM_message_profile_ID = 'Z23^CDCPHINVS';

        }


        $res = sqlStatement($sql);
        $r = sqlFetchArray($res);

        $D = "\r";
        $delimiter = "------------------------------------------------------------------------------------------------------------------";
        $nowdate = date('YmdhmsO');
        $now = date('YmdGi');
        $now1 = date('Y-m-d G:i');

        //create the MSH Loop
        $content = '';
        $content .=
            "MSH|" .     //1. Field Seperator R OK, QOK
            "^~\&|" .    //2. Encoding Characters R OK Qok
            "OPENEMR|" . //3. Sending App optional OK
            $GLOBALS['IMM_sendingfacility'] . "|" . //4. Sending facility OK, QOK
            "|" .        //5. receiving application Ignored  OK
            $GLOBALS['IMM_receivingfacility'] . "|" . //6. Receiving Facility OK
            $nowdate . "|" . //7. date/time message OK  //***Update - added timezone
            "|" .       //8. Security - ignored OK
            $IMM_message_type . "|" . // 9. message type - Required OK
            date('Ymdhms') . $pid . preg_replace("/[^A-Za-z0-9 ]/", '', $r['immunizationtitle']) . "|" .  //  OK 10. Message control ID (must be unique for a given day) Required
            "P|" . //11. Processing ID (Only value accepted is �P� for production. All other values will cause themessage to be rejected.)
            $GLOBALS['IMM_hl7versionID'] . "|" . //12 Version ID (2.5.1 as of current) OK
            "|" .     //13. Sequence number
            "|" .     //14. Continuation pointer
            "AL|" .     //15. Accept acknowledgment type
            "AL|" .     //16. Application acknowledgment type
            "|" .     //17. Country code //Not supported by CAIR
            "|" .     //18. Character set //Not supported by CAIR
            "|" .     //19. Principal language of message //Not supported by CAIR
            "|" .     //20. Alternate character set handling scheme//Not supported by CAIR
            "$IMM_message_profile_ID|" .     //21. Sites may use this field to assert adherence to, or reference, a message profile
            $GLOBALS['IMM_CAIR_ID'] . $D;     //22. Responsible Sending Org

        return $content;
    }

    //generates an array so we can display data to a view
    public static function MSH_to_array($data)
    {

        $out = array();
        $out ['encoding_characters'] = $data[1];
        $out ['sending_application'] = $data[2];
        $out ['sending_facility'] = $data[3];
        $out ['receiving_application'] = $data[4];
        $out ['receiving_facility'] = $data[5];
        $out ['date_time_of_message'] = $data[6];
        $out ['security'] = $data[7];
        $out ['message_type'] = $data[8];
        $out ['message_control_id'] = $data[9];
        $out ['processing_id'] = $data[10];
        $out ['version_id'] = $data[11];
        $out ['sequence_number'] = $data[12];
        $out ['continuation_pointer'] = $data[13];
        $out ['accept_ack_type'] = $data[14];
        $out ['application_ack_type'] = $data[15];
        $out ['country_code'] = $data[16];
        $out ['character_set'] = $data[17];
        $out ['principal_language_of_message'] = $data[18];
        $out ['alternate_charc_set_handle_scheme'] = $data[19];
        $out ['message_profile_id'] = $data[20];
        $out ['responsible_sending_organization'] = $data[21];
        $out ['responsible_receiving_organization'] = $data[22];

        return $out;;

    }

    public static function QBP_to_array($message)
    {

        $out = array();
        $out['MSH'] = array();
        $out['QPD'] = array();
        $out['RCP'] = array();

        $message = explode(PHP_EOL, $message);
        $delimiter = "|";

        foreach ($message as $segment) {

            $data = explode($delimiter, $segment);

            if ($data[0] === "MSH") {

                $out['MSH'] = self::MSH_to_array($data);

            } else if ($data[0] === "QPD") {

                $out['QPD'] = self::QPD_to_array($data);

            } else if ($data[0] === " RCP ") {

                $out['RCP'] = self::RCP_to_array($data);
            }


        }

        return $out;

    }

    public static function PID_to_array($data)
    {

        $out = array();
        $out['set_id'] = $data[1];
        $out['patient_id'] = $data[2];
        $out['patient_identifier_list'] = $data[3];
        $out['alternative_patient_id'] = $data[4];
        $out['patient_name'] = $data[5];
        $out['mothers_maiden_name'] = $data[6];
        $out['dob'] = $data[7];
        $out['sex'] = $data[8];
        $out['patient_alias'] = $data[9];
        $out['race'] = $data[10];
        $out['patient_address'] = $data[11];
        $out['county_code'] = $data[12];
        $out['phone_home'] = $data[13];
        $out['phone_business'] = $data[14];
        $out['primary_lang'] = $data[15];
        $out['marital_status'] = $data[16];
        $out['religion'] = $data[17];
        $out['patient_acct_number'] = $data[18];
        $out['ssn'] = $data[19];
        $out['drivers_license_number'] = $data[20];
        $out['mothers_id'] = $data[21];
        $out['ethnic_group'] = $data[22];
        $out['birth_place'] = $data[23];
        $out['multiple_birth_indicator'] = $data[24];
        $out['birth_order'] = $data[25];
        $out['citizenship'] = $data[26];
        $out['veterans_military_status'] = $data[27];
        $out['nationality'] = $data[28];
        $out['patient_death_and_time'] = $data[29];
        $out['patient_death_indicator'] = $data[30];
        $out['last_update_date_and_time'] = $data[33];


    }

    public static function PD1_to_array($data)
    {

        $out = array();
        $out['publicity_code'] = $data[11];
        $out['protection_indicator'] = $data[12];
        $out['protection_indicator_effective_date'] = $data[13];
        $out['immunization_registry_status'] = $data[16];
        $out['immunization_registry_status_eff_date'] = $data[17];


    }

    //Generate the Patient Identifier List
    public function genHL7_PID($pid, $immunization_id)
    {

        $content = '';
        $D = "\r";
        $sql = "Select *, concat(p.lname, '^', p.fname) as patientname, DATE_FORMAT(p.DOB,'%Y%m%d')" .
            " as DOB, concat(p.street, '^^', p.city, '^', p.state, '^', p.postal_code) as address, mothersname from patient_data p where pid = ?";

        $res = sqlStatement($sql, array($pid));

        while ($r = sqlFetchArray($res)) {

            if ($r['sex'] === 'Male') $r['sex'] = 'M';
            if ($r['sex'] === 'Female') $r['sex'] = 'F';
            if ($r['sex'] != 'M' && $r['sex'] != 'F') $r['sex'] = 'U';
            if ($r['status'] === 'married') $r['status'] = 'M';
            if ($r['status'] === 'single') $r['status'] = 'S';
            if ($r['status'] === 'divorced') $r['status'] = 'D';
            if ($r['status'] === 'widowed') $r['status'] = 'W';
            if ($r['status'] === 'separated') $r['status'] = 'A';
            if ($r['status'] === 'domestic partner') $r['status'] = 'P';
            if ($r['phone_home'] == '' || $r['phone_home'] == null) $r['phone_home'] = $r['phone_cell'];


            $content .= "PID|" . // [[ 3.72 ]]
                "$immunization_id|" . // 1. Set id (For logging purposes, this is going to be the ID of the immuniation in the immunizations table
                "|" . // 2. (B)Patient id //This is ignored
                $r['pid'] . "^^^SantiagoPeds^PI" . "|" . // 3. (R) Patient indentifier list. OK
                "|" . // 4. (B) Alternate PID
                $r['patientname'] . "|" . // 5.R. Name OK
                "|" . // 6. Mother Maiden Name OK
                $r['DOB'] . "|" . // 7. Date, time of birth OK
                $r['sex'] . "|" . // 8. Sex OK
                "|" . // 9.B Patient Alias OK
                "2106-3^" . $r['race'] . "^HL70005" . "|" . // 10. Race // Ram change
                $r['address'] . "^^M" . "|" . // 11. Address. Default to address type  Mailing Address(M)
                "|" . // 12. county code
                "^PRN^^^^" . self::format_phone($r['phone_home']) . "|" . // 13. Phone Home. Default to Primary Home Number(PRN)
                "^WPN^^^^" . self::format_phone($r['phone_biz']) . "|" . // 14. Phone Work.
                "|" . // 15. Primary language
                $r['status'] . "|" . // 16. Marital status
                "|" . // 17. Religion
                "|" . // 18. patient Account Number
                $r['ss'] . "|" . // 19.B SSN Number
                $r['drivers_license'] . "|" . // 20.B Driver license number
                "|" . // 21. Mothers Identifier
                "^^|" . // 22. Ethnic Group
                "|" . // 23. Birth Place
                "|" . // 24. Multiple birth indicator
                "|" . // 25. Birth order
                "|" . // 26. Citizenship
                "|" . // 27. Veteran military status
                "|" . // 28.B Nationality
                "|" . // 29. Patient Death Date and Time
                "|" . // 30. Patient Death Indicator
                "|" . // 31. Identity Unknown Indicator
                "|" . // 32. Identity Reliability Code
                "|" . // 33. Last Update Date/Time
                "|" . // 34. Last Update Facility
                "|" . // 35. Species Code
                "|" . // 36. Breed Code
                $D;


        }


        return $content;


    }

    //GEnerate the PD1 segment
    public function genHL7_PD1($pid, $immunization_id)
    {

        $content = '';
        $D = "\r";
        $sql = "Select *, concat(p.lname, '^', p.fname) as patientname, DATE_FORMAT(p.DOB,'%Y%m%d')" .
            " as DOB, concat(p.street, '^^', p.city, '^', p.state, '^', p.postal_code) as address, mothersname from patient_data p where pid = ?";

        $res = sqlStatement($sql, array($pid));
        while ($r = sqlFetchArray($res)) {

            if (!isset($r['data_sharing']))
                $r['data_sharing'] = "N";

            if (!isset($r['data_sharing_date']))
                $r['data_sharing_date'] = '';

            $content .= "PD1|" .
                "|" . // 1. living dependency
                "|" . // 2. living arrangment
                "^^^^^^^^^" . $GLOBALS['IMM_CAIR_ID'] . "|" . // 3. Patient primary facility (R)
                "|" . // 4. Primary care provider (can be empty)
                "|" . // 5. Student Indicator
                "|" . // 6. Handicap
                "|" . // 7. Living Will
                "|" . // 8. Organ Donor
                "|" . // 9. Seperate bill
                "|" . // 10. Duplicate Patient
                "^^^|" . // 11. Publicity Code (may be empty)
                $r['data_sharing'] . "|" . // 12. Protection Indicator (R)
                $r['data_sharing_date'] . // 13. Protection Indicator effective date
                "|" . // 14. Place of worship
                "|" . // 15. Advance directive code
                "|" . // 16. Immunization registry status
                "|" . // 17. Immunization registry status effective date
                "|" . // 18. Publicity code effective date
                $D;
        }

        return $content;
    }

    //Generate the Next of Kin Segment.  This is not used currently and not required.
    public function genHL7_NK1()
    {
    }

    //Generate the Order Control Segment.  The fields in ths segment can be blank
    public static function genHL7_ORC()
    {
        $D = "\r";
        $content = "ORC" . // ORC mandatory for RXA
            "|" .
            "RE|" . //1. Order Control
            "|" . //2. Placer Order Number
            "|" . //3. Filler Order Number
            "|" . //4. Placer Group Number
            "|" . //5. Order Status
            "|" . //6. Response Flag
            "|" . //7. Quantity/Timing
            "|" . //8. Parent
            "|" . //9. Date/Time of Transaction
            "|" . //10. Entered By
            "|" . //11. Verified By
            "|" . //12. Ordering Provider
            "|" . //13. Enterer's Location
            "|" . //14. Call Back Phone Number
            "|" . //15. Order Effective Date/Time
            "|" . //16. Order Control Code Reason
            "|" . //17. Entering Organization
            "|" . //18. Entering Device
            "|" . //19. Action By
            "|" . //20. Advanced Beneficiary Notice Code
            "|" . //21. Ordering Facility Name
            "|" . //22. Ordering Facility Address
            "|" . //23. Ordering Facility Phone Number
            "|" . //24. Ordering Provider Address
            "|" . //25. Order Status Modifier
            "|" . //26. Advanced Beneficiary Notice Override Reason
            "|" . //27. Filler's Expected Availability Date/Time
            "|" . //28. Confidentiality Code
            "|" . //29. Order Type
            "|" . //30. Enterer Authorization Mode
            "|" . //31. Parent Universal Service Identifier
            $D;

        return $content;


    }

    //Generate the PHarmacy/Treatment Administration Segment
    public static function genHL7_RXA($imm_id)
    {
        /////This is the next step
        $D = "\r";

        $sql = " select *, i.id as immunizationid,  " .
            " i.route as route, i.administration_site as site, i.update_date, i.submitted, " .
            " DATE_FORMAT(i.vis_date,'%Y%m%d') as immunizationdate, amount_administered, " .
            " DATE_FORMAT(i.administered_date,'%Y%m%d') as administered_date, ndc, cvx_code " .

            " from immunizations i " .
            " where i.id = ? ";

        $res = sqlStatement($sql, array($imm_id));
        while ($r = sqlFetchArray($res)) {

            if ($r['historical'] == '0')
                $r['historical'] = '00';
            if ($r['historical'] == '1')
                $r['historical'] = '01';
            //get the NDC11 using the GTIN.

            $f = self::getNDC11($r['ndc']);
            $r['ndc'] = $f['NDC11'];
            $r['immunizationtitle'] = $f['description'];
            //if the NDC11 is not available, we can use the cvx code.


            $code = isset($r['ndc']) && $r['ndc'] != null ? $r['ndc'] : self::format_cvx_code($r['cvx_code']);
            $code_type = isset($r['ndc']) && $r['ndc'] != null ? 'NDC' : 'CVX';
            $admin_amt = $r['amount_administered'] > 0 ? $r['amount_administered'] : '999';


            $content = "RXA|" .
                "0|" . // 1. Give Sub-ID Counter
                "1|" . // 2. Administration Sub-ID Counter
                $r['administered_date'] . "|" . // 3. Date/Time Start of Administration
                $r['administered_date'] . "|" . // 4. Date/Time End of Administration

                $code . "^" . $r['immunizationtitle'] . "^" . $code_type . "|" . // 5. Administration Code(CVX)

                "$admin_amt|" . // 6. Administered Amount.
                "mL|" . // 7. Administered Units
                "|" . // 8. Administered Dosage Form
                $r['historical'] . "^^NIP001|" . // 9. Administration Notes (determines if from an historical record or new immunization)
                "|" . // 10. Administering Provider
                "^^^" . $GLOBALS['IMM_CAIR_ID'] . "|" . // 11. Administered-at Location
                "|" . // 12. Administered Per (Time Unit)
                "|" . // 13. Administered Strength
                "|" . // 14. Administered Strength Units
                $r['lot_number'] . "|" . // 15. Substance Lot Number
                "|" . // 16. Substance Expiration Date
                "MSD" . "^" . $r['manufacturer'] . "^" . "HL70227" . "|" . // 17. Substance Manufacturer Name
                "|" . // 18. Substance/Treatment Refusal Reason
                "|" . // 19.Indication
                "|" . // 20.Completion Status (CP, PA, or empty)
                "A" . // 21.Action Code - RXA ("A" Add, "U" update, "D" delete)
                "$D";


            $content .= "RXR|" .
                $r['route'] . "^^HL70162^^^" . "|" .     //1. Route, required but may be empty
                $r['site'] . "^^HL70163^^^" . "|" .                 //2. Site.  required, but may be empty
                "|" .                 //3. administration device - ignored
                "|" .                 //4. administration method - ignored
                "|" .                 //5. routing instruction - ignored
                "$D";


            $content .= "OBX|" .
                "1|" .              // 1. Set ID - OBX (required)
                "CE|" .            // 2. Value Type (required)
                "64994-7^^LN^^^|" .              //3. Observation Identifier Required if RXA-9 value is '00'(required)
                "|" .              //4. Observation Sub-Id (required)
                $r['vfc'] . "^^|" .              //5. Observation Value (required)
                "|" .              //6. Units (ignored)
                "|" .              //7 Reference Ranges (ignored)
                "|" .              //8 Abnormal flags (ignored)
                "|" .              //9 Probability (ignored)
                "|" .              //10 Nature of Abnormal test (ignored)
                "F|" .              //11 Observsation Result Status (Required)
                "|" .              //12 eff date of ref range values (ignored)
                "|" .              //13 User defined access Checks (ignored)
                "|" .              //14 Date/Time of the Observation (required, but may be empty)
                "||||||||||" .        //15-25 ignored.
                "$D";

        }

        return $content;

    }



    //The MSH segment is declared above

    //Message Acknowledgment
    static private function MSA_to_array($data)
    {

        $out = array();
        //AA-Accepted without error, AE-message processed, errors
        //Reported.  AR-message rejected
        $out['ack_code'] = $data[1];
        $out['message_control_id'] = $data[2];
        $out['status'] = $data[6];
        return $out;

    }


    //Sent by both ACK and RSP.  Each ERR will have its own segment
    //There can be more than one ERR
    static private function ERR_to_array($data)
    {

        $error = array();
        $error['error_code_and_location'] = $data[1]; //Not supported in 2.5.1
        $error['error_location'] = $data[2]; //required
        $error['HL7_error_code'] = $data[3];//Refer to HL7 table 0357
        $error['severity'] = $data[4];//E for error, W for Warning
        $error['application_error_code'] = $data[5]; //Required but may be empty
        $error['application_error_parameter'] = $data[6]; //O
        $error['diag_information'] = $data[7]; //O
        $error['user_message'] = $data[8];//Required, but may be empty

        return $error;

    }


    //takes in the array and turns it to info we can use for display
    static private function QAK_to_array($data)
    {

        $out = array();
        //AA-Accepted without error, AE-message processed, errors
        //Reported.  AR-message rejected
        $out['query_Tag'] = $data[1]; //value sent in qpd-2
        $out['query_response_status'] = $data[2]; //OK, NF, AE, AR, TM. PD
        $out['message_query_name'] = $data[3];
        return $out;
    }

    static private function ORC_to_array($data)
    {

        $out = array();
        $out['order_control'] = $data[1];
        $out['place_order_number'] = $data[2];
        $out['filler_order_number'] = $data[3];

        return $out;
    }

    static private function RXA_to_array($data)
    {

        $out = array();
        $out['give_sub_id_counter'] = $data[1];
        $out['admin_sub_id_counter'] = $data[2];
        $out['datetime_start_of_administration'] = $data[3];
        $out['datetime_end_of_administration'] = $data[4];
        $out['administration_code'] = $data[5];
        $out['administered_amt'] = $data[6];
        $out['administered_units'] = $data[7];
        $out['administered_dosage_form'] = $data[8];
        $out['administered_notes'] = $data[9];
        $out['administering_provider'] = $data[10];
        $out['administered_at_location'] = $data[11];
        $out['administered_per_time_unit'] = $data[12];
        $out['administered_strength'] = $data[13];
        $out['administered_strength_unit'] = $data[14];
        $out['substance_lot_number'] = $data[15];
        $out['substance_exp_date'] = $data[16];
        $out['substance_lot_mfr_name'] = $data[17];
        $out['substance_refusal_reason'] = $data[18];
        $out['indication'] = $data[19];
        $out['completion_status'] = $data[20];
        $out['action_code'] = $data[21];
        return $out;

    }

    static private function RXR_to_array($data)
    {

        $out = array();
        $out['route'] = $data[1];
        $out['administration_site'] = $data[2];

        return $out;

    }

    static private function OBX_to_array($data)
    {

        $out = array();
        $out['sequence_id'] = $data[1];
        $out['value_type'] = $data[2];
        $out['observation_id'] = $data[3];
        $out['observation_subid'] = $data[4];
        $out['observation_value'] = $data[5];
        $out['units'] = $data[6];
        $out['ref_ranges'] = $data[7];
        $out['abnormal_flags'] = $data[8];
        $out['probability'] = $data[9];
        $out['nature_of_abnormal_test'] = $data[10];
        $out['observation_result_status'] = $data[11];


        return $out;


    }

    //takes in the array and turns it to info we can use for display
    //Query Parameter Definition
    static private function QPD_to_array($data)
    {

        $out = array();
        //AA-Accepted without error, AE-message processed, errors
        //Reported.  AR-message rejected
        $out['message_query_name'] = $data[1]; //value sent in qpd-2
        $out['query_tag'] = $data[2]; //OK, NF, AE, AR, TM. PD
        $out['patient_list'] = $data[3]; //patient identifier
        $out['patient_name'] = $data[4];
        $out['patient_mother_name'] = $data[5];
        $out['patient_dob'] = $data[6];
        $out['patient_sex'] = $data[7];
        $out['patient_address'] = $data[8];
        $out['patient_home_phone'] = $data[9];
        $out['patient_mult_birth_indicator'] = $data[10];
        $out['patient_birth_order'] = $data[11];
        $out['client_last_update_date'] = $data[12];
        $out['client_last_update_facility'] = $data[13];
        return $out;
    }

    static private function RCP_to_array($data)
    {

        $out = array();

        $out['query_priority'] = $data[1];
        $out['quanity_limited_request'] = $data[2];
        $out['response_modality'] = $data[3];
        return $out;
    }

    //This builds the QPD segment
    private static function genHL7_QPD($pid, $type = "history")
    {
        $D = "\r";

        if ($type === 'projection') {

            $qpd1 = 'Z44^Request Evaluated History and Forecast^HL70471';

        } else {

            $qpd1 = 'Z34^Request Complete Immunization History^HL70471';

        }

        $sql = " select pd.*, concat(pd.lname, '^', pd.fname,'^', pd.mname,'^^^^L') as patientname, " .
            " concat(pd.street, '^^', pd.city, '^', pd.state, '^', pd.postal_code) as address, " .
            " DATE_FORMAT(pd.DOB,'%Y%m%d') as dob " .
            " from patient_data pd where pid = $pid ";

        $res = sqlStatement($sql);
        while ($r = sqlFetchArray($res)) {

            if ($r['sex'] === 'Male') $r['sex'] = 'M';
            if ($r['sex'] === 'Female') $r['sex'] = 'F';
            if ($r['sex'] != 'M' && $r['sex'] != 'F') $r['sex'] = 'U';
            if ($r['status'] === 'married') $r['status'] = 'M';
            if ($r['status'] === 'single') $r['status'] = 'S';
            if ($r['status'] === 'divorced') $r['status'] = 'D';
            if ($r['status'] === 'widowed') $r['status'] = 'W';
            if ($r['status'] === 'separated') $r['status'] = 'A';
            if ($r['status'] === 'domestic partner') $r['status'] = 'P';


            $out = 'QPD|' .
                $qpd1 . "|" . //1) Message query name
                $r['pubpid'] . '-' . $r['pid'] . '-' . $r['lname'] . time() . "|" . //(2) query tag, a unique ID
                $r['pid'] . "|" . //(3) patient ID
                $r['patientname'] . "|" . //(4) Patient name
                "|" .           //(5) patient mother's name
                $r['dob'] . "|" . //(6) DOB
                $r['sex'] . "|" . //(7) Sex, m,f, or u
                $r['address'] . "|" . //(8) DOB
                "^PRN^PH^^^" . self::format_phone($r['phone_home']) . "|" . //(9)
                "|" . // (10) multiple birth indicator
                "$D";
        }
        return $out;
    }

    private static function genHL7_RCP($max)
    {

        $D = "\r";
        $content = '';
        $content .= "RCP|I|" .
            "$max^RD" .
            "$D";

        return $content;

    }

    //We take the message generated from gem_HL7_CAIR_QBP and parse it so we
    //can extract information to the user.


    private function parseMothersName($string)
    {

        //This is for PID-6 for the CAIR Registry.
        $name = explode($string, ' ');
        //get the size of the array.
        //if the size is 2 we give the first member as the first name and the second as the second name
        if (sizeof($name) == 2) {

            return "$name[2]^$name[1]^^^^^^";

        } else if (sizeof($name) > 2) {

            //if the size is 3 or more we give the first member as the first and the remaining as the second
            return "$name[2] $name[3]^$name[1]^^^^^^";

        } else {

            return "";

        }


    }

    public static function explodeRXA5($msg, $delimiter = "^")
    {

        $out = array();
        $data = explode($delimiter, $msg);
        $out['identifier'] = $data[1];
        $out['text'] = $data[2];
        $out['name_of_coding'] = $data[3];
        $out['alt_id'] = $data[4];
        $out['alt_txt'] = $data[5];
        $out['mane_of_alt_coding_system'] = $data[6];

        return $out;

    }

    public static function getLocalImmHist($pid)
    {

        $out = array();
        $sql = "select  *, i.id as IMMID from immunizations i join codes c " .
            " on i.cvx_code = c.code  where code_type = 100 and patient_id = $pid";

        $res = sqlStatement($sql);
        while ($row = sqlFetchArray($res)) {

            array_push($out, $row);
        }

        return $out;


    }

    public static function getLocalQBP($pid)
    {

        $today = date('Y-m-d');
        $sql = "SELECT * from immunization_QBP_log where pid = ? and submit_date >= ? order by id desc ";
        $res = sqlQuery($sql, array($pid, $today));
        return $res;


    }

    private function parse_vaccine_json_datatables($array)
    {


    }

    //Here we take the RSP array and convert it into a json array
    //to display in datatables.  The format of the json sting is the following:
    //Fields: Vaccine Group, Date admin, Trade Name, Dose, Owned, Reaction, Hist
    //We need to create an array for each vaccine group and then push the individual vaccine to each of those groups
    //Some vaccines will be administered to more than one vaccine group.  for example, DTap would belong to
    //DTP/ap, Hib,and Polio
    public static function history_to_json_datatables($array)
    {

        //build the array.
        //read in the ORC

        $vaccine_groups = array();
        $row = array();
        $out['data'] = array();
        foreach ($array['ORC'] as $index => $vaccine) {

            $row['trade_name'] = explode('^', $array['RXA'][$index]['administration_code'])[1];
            $row['date_of_admin'] = substr($array['RXA'][$index]['datetime_start_of_administration'], 0, 4) . "-" . substr($array['RXA'][$index]['datetime_start_of_administration'], 4, 2) . "-" . substr($array['RXA'][$index]['datetime_start_of_administration'], 6);
            $row['series'] = "x of Y";
            $row['vaccines_due_next'] = "date not here";
            $row['date_vaccine_due'] = "date not here";
            $row['earliest_date_to_give'] = "date not here";
            $row['forcast_logic'] = "date not here";
            $row['owned'] = "Y/N";
            $row['reaction'] = "N";
            $row['hist'] = "Y/N";


            //get the vaccine group, see if it exists. If it exists we push the vaccine to that group.  if not we
            //create the group and push this vaccine to that row.
            $lastSubID = 1;
            foreach ($array['OBX'][$index] as $obxIndex => $obx) {


                //get the available values.  We check for the sub-id OBX-4.  If the number changes, it is a new vaccine,
                //but can be part of the same group

                //Check sub-id.  If sub-id equals the last id, we push to this row.  if the sub-id does not
                //equal the last id it is a new vaccine so we push this row to data

                $currentSubID = $obx['observation_subid'];


                if ($currentSubID > $lastSubID) {

                    array_push($out['data'], $row);

                }

                $vaccine_type = explode('^', $obx['observation_id'])[0];
                switch ($vaccine_type) {
                    case '30973-2': //dose number in series

                        if ($obx['observation_value'] == 999) {

                            $row['series'] = '';

                        } else $row['series'] = $obx['observation_value'];

                        break;

                    case '30979-9': //Vaccines due next
                        $row['vaccines_due_next'] = $obx['observation_value'];
                        break;

                    case '30980-7': //Date vaccine due
                        $row['date_vaccine_due'] = $obx['observation_value'];
                        break;

                    case '30981-5': //Earliest date to give
                        $row['earliest_date_to_give'] = $obx['observation_value'];
                        break;

                    case '30982-3': //Reason applied by forecast logic to project this vaccine
                        $row['forcast_logic'] = $obx['observation_value'];
                        break;

                    //Component vaccine type
                    case '38890-0':
                        $vaccine_group = explode("^", $obx['observation_value'])[1];

                        //if the vaccine group already exists we put a blank value for the array
                        if (in_array($vaccine_group, $vaccine_groups)) {


                        } else {


                            array_push($vaccine_groups, $vaccine_group);

                        }

                        $row['vaccine_group'] = $vaccine_group;

                        break;

                    case '31044-1':

                        $row['reaction'] = $obx['observation_value'];
                        break;


                }

                $lastSubID = $currentSubID;

            }

            array_push($out['data'], $row);
        }


        return json_encode($out['data']);

    }

    public static function CAIR_message_status($string)
    {

        if ($string == 'OK') {

            return 'No Errors Found';

        } else if ($string == 'NF') {

            return '<h4>Patient not found in CAIR2 based on query received. Does demographic info match with CAIR?</h4>';

        } else if ($string == 'AE') {

            return "<h4>APPLICATION ERROR.</h4> Query had an error in content or format";


        } else if ($string == 'AR') {

            return "<h4>QBP message can be parsed as a query, but contains fatal errors</h4>";

        } else if ($string == 'NF') {

            return "<h4>Too many possible matches to patient sent in the query.</h4> Query must be narrowed down. Does demographic info match with CAIR?";

        } else if ($string == 'PD') {

            return "<h4>Patient’s data marked as ‘Not Shared’ in CAIR2.</h4> ";

        }


    }

    public function isMultipleVaccine($RXA)
    {

        $multipleVaccineArray = array('MMRV', 'DTaP-IPV');
        $singleVaccineArray = array('Flu trivalent injectable', 'PCV13', 'DTaP', 'Flu NOS', 'HepA-Ped 2 Dose',
            'HepB-Peds', 'Hib, NOS', 'MMR', 'Polio-Inject', 'Varicella', 'Rotavirus, Pent');
    }

    public static function vaccineMap($vaccine)
    {
        $string = 0;
        if (strpos(strtolower($vaccine), 'polio') !== false)
            return "polio";
        if (strpos(strtolower($vaccine), 'h1n1') !== false)
            return "h1n1";
        if (strpos(strtolower($vaccine), 'dtp/ap') !== false)
            return "dtp";
        if (strpos(strtolower($vaccine), 'tdap') !== false)
            return "tdap";
        if (strpos(strtolower($vaccine), 'dtap') !== false)
            return "dtap";
        if (strpos(strtolower($vaccine), 'influenza') !== false)
            return "flu";
        if (strpos(strtolower($vaccine), 'h1n1') !== false)
            return "h1n1";
        if (strpos(strtolower($vaccine), 'menb') !== false)
            return "meningb";
        if (strpos(strtolower($vaccine), 'men a') !== false)
            return "mening";
        if (strpos(strtolower($vaccine), 'rotavirus') !== false)
            return "rota";
        if (strpos(strtolower($vaccine), 'td/tdap') !== false)
            return "tdap";
        if (strpos(strtolower($vaccine), 'hib') !== false)
            return "hib";
        if (strpos(strtolower($vaccine), 'hepb') !== false)
            return "hepb";
        if (strpos(strtolower($vaccine), 'pneumoconjugate') !== false)
            return "pc";
        if (strpos(strtolower($vaccine), 'pneumococcal') !== false)
            return "pp";
        if (strpos(strtolower($vaccine), 'hepa') !== false)
            return "hav";
        if (strpos(strtolower($vaccine), 'mmr') !== false)
            return "mmr";
        if (strpos(strtolower($vaccine), 'varicella') !== false)
            return "vzv";
        if (strpos(strtolower($vaccine), 'hpv') !== false)
            return "hpv";
        if (strpos(strtolower($vaccine), 'zos') !== false)
            return "zoster";
        if (strpos(strtolower($vaccine), 'TB') !== false)
            return "tb";
        if (strpos(strtolower($vaccine), 'no vaccine') !== false)
            return "projection";

        return "XXXXXXXX";
    }
    //This allows us to get the max number of spots on the CAIR Yellow Card, so we can
    //make sure we have the most recent vaccines.
    public static function vaccineMaxDose($vaccine)
    {

        if (strpos(strtolower($vaccine), 'polio') !== false)
            return 5;
        if (strpos(strtolower($vaccine), 'h1n1') !== false)
            return 2;
        if (strpos(strtolower($vaccine), 'dtp/ap') !== false)
            return 5;
        if (strpos(strtolower($vaccine), 'tdap') !== false)
            return 1;
        if (strpos(strtolower($vaccine), 'dtap') !== false)
            return 5;
        if (strpos(strtolower($vaccine), 'influenza') !== false)
            return 4;
        if (strpos(strtolower($vaccine), 'h1n1') !== false)
            return 2;
        if (strpos(strtolower($vaccine), 'menb') !== false)
            return 3;
        if (strpos(strtolower($vaccine), 'men a') !== false)
            return 2;
        if (strpos(strtolower($vaccine), 'rotavirus') !== false)
            return 3;
        if (strpos(strtolower($vaccine), 'td/tdap') !== false)
            return 1;
        if (strpos(strtolower($vaccine), 'hib') !== false)
            return 4;
        if (strpos(strtolower($vaccine), 'hepb') !== false)
            return 3;
        if (strpos(strtolower($vaccine), 'pneumoconjugate') !== false)
            return 4;
        if (strpos(strtolower($vaccine), 'pneumococcal') !== false)
            return 1;
        if (strpos(strtolower($vaccine), 'hepa') !== false)
            return 2;
        if (strpos(strtolower($vaccine), 'mmr') !== false)
            return 2;
        if (strpos(strtolower($vaccine), 'varicella') !== false)
            return 2;
        if (strpos(strtolower($vaccine), 'hpv') !== false)
            return 3;
        if (strpos(strtolower($vaccine), 'zos') !== false)
            return 4;
        if (strpos(strtolower($vaccine), 'TB') !== false)
            return 3;

        return "XXXXXXXX";
    }

    public static function writeToForm(&$valueArray, $vaccine, $date, $dose, $location, $tradeName ="sdsds", $reaction = '')
    {
        //get the type of vaccine string
        $base = self::vaccineMap($vaccine);
        if (!isset($valueArray[$vaccine]) && strpos($vaccine, '_proj' === false)) {
            $valueArray[$vaccine][$dose] = array();

        }
        //convert the date to m/d/Y
        $date = date('m/d/Y', strtotime($date));

        if (strpos(strtolower($base), 'tb') === false && !strpos($vaccine, '_proj')) {
            if (isset($date) && $date != null) {
                $valueArray[$vaccine][$dose][$base . "_" . $dose . '_date'] = $date;

            } else {
                $valueArray[$vaccine][$dose][$base . "_" . $dose . '_date'] = "N/A";
            }

            if (isset($location) && $location != null) {
                $valueArray[$vaccine][$dose][$base . "_" . $dose . "_location"] = $location;

            } else {
                $valueArray[$vaccine][$dose][$base . "_" . $dose . "_location"] = "location not available";

            }

            if (isset($tradeName) && $tradeName != null) {
                $valueArray[$vaccine][$dose][$base . "_" . $dose . "_name"] = $tradeName;

            } else {
                $valueArray[$vaccine][$dose][$base . "_" . $dose . "_name"] ="";

            }


        } else if (strpos($vaccine, '_proj')) {
            $proj_dose = self::vaccineMaxDose($vaccine);
            $dose = intval($dose) - 1;
            $dose = ($dose > $proj_dose) ? $proj_dose : $dose;
            $valueArray[$vaccine][$dose][$base . "_" . $dose . '_datenext'] = $date;
        }
        //$success = populateValues($flu_array, $rsp_array_projection, 'flu', $patient_data);
        //todo: do an error check.  Make sure the most recent vaccines are shown.
        //handle $dose = 999
        $max = self::vaccineMaxDose($vaccine);


        return 1;
    }

    //This returns values needed for different tests, mostly related to TST and TB.
    //This can still be usd for other LBF's.
    public static function getXTest($pid, $limit = 3, $wildcard = "(TST)")
    {
        //todo: Verify that an LBF exists.  If it doesn't break nicely.

        //This is taken from the LBF TST
        $tb_tests['data'] = array();

        $sql = 'SELECT distinct(encounter), f.* FROM forms f  ' .
            'where form_name like ? and pid = ? and deleted = 0' .
            ' ORDER BY encounter DESC ';

        //Here we grab the encounters
        $res = sqlStatement($sql, array( $wildcard, $pid));

        while ($row = sqlFetchArray($res)) {
            //initialize array with encounter and form date
            $tb_result = array('encounter' => $row['encounter'], 'form_date' => date('m/d/Y', strtotime($row['date']))); //initialize the array

            //add user, must pull from user table first.
            $user = sqlQuery("SELECT fname, lname, username FROM users where username= ?", array($row['user']));
            $tb_result['user'] = $user['fname'] . " " . $user['lname'];
            //We get the individual encounters
            $sql2 = "SELECT encounter, user, field_id, field_value FROM forms f " .
                "join lbf_data lbf on lbf.form_id = f.form_id where form_name " .
                "like ? and encounter = {$row['encounter']} ORDER BY `date` DESC ";

            $res2 = sqlStatement($sql2, array($wildcard));

            while ($row2 = sqlFetchArray($res2)) {
                //get field_id
                $tb_result[$row2['field_id']] = $row2['field_value'];
            }
            //push the individual test to the array of tests.
            array_push($tb_tests['data'], $tb_result);
        }

        //Send the result in JSON format
        return $tb_tests['data'];
    }

    //Going to use this to test the layout, make sure it is all working
    //This is not used during runtime
    public static function testLayout(&$patient_data, $imm, $i = 5)
    {
        for ($index = 1; $index <= $i; $index++) {
            $patient_data[$imm . $index . "date"] = "date" . $index.$imm;
            $patient_data[$imm . $index . ""] = "location" . $index.$imm;
            $patient_data[$imm . $index . "datenext"] = "daten" . $index.$imm;
        }
    }

    public static function sortVaccines(&$vaccines)
    {

        //WE are only concerned with vaccines.  Skip everything that is not an array
        foreach ($vaccines as $index => $entry) {
            if (!is_array($entry) || empty($entry)) {
                continue;
            }

            //Get unique values

            usort($vaccines[$index] ,function($a,$b){
                if(strtotime($a['date']) == strtotime($b['date'])) return 0;
                else return strtotime($a['date']) - strtotime($b['date']) ;
            });

            //we get a unique vaccine (this is because of the bug on CAIR's system)
            $vaccines[$index] = array_unique($vaccines[$index], SORT_REGULAR);
            $vaccines[$index] = array_values($vaccines[$index]);

            //After this is sorted we need to shift the array the number of doses above the max dose

            echo '';

        }
    }

    public static function shiftVaccines(&$vaccines){
        foreach ($vaccines as $index => $entry) {
            if (!is_array($entry) || empty($entry)) {
                continue;
            }

            //Get the number of doses that were administered.
            $numDoses = sizeof($entry);

            //compare the the max number of doses allowed by the CAIR Yellow card sheet
            $maxDose = self::vaccineMaxDose($index);
            //if they are equal or less, do nothing
            if ($numDoses <= $maxDose) {
                //Do nothing
            } else {
                echo "Vaccine $index displays only the most recent doses.  # of doses exceeds room of form";
                $shift = $numDoses - $maxDose;
                $vaccines[$index] = array_slice( $vaccines[$index], $shift);
            }
            //else we need to shift the array by the number of doses.
            echo '';
        }


    }
    public static function handleProjections(&$vaccines){
        foreach ($vaccines as $index => $vaccine) {
            //if the $index does not contain _proj, we want to continue
            if(strpos($index, '_proj') === false){
                continue;
            }
            $i =  str_replace('_proj', '', $index);
            $nextDateDoseNum = sizeof($vaccines[$i]) - 1;
            $nextDateDoseExp = $vaccine[0]['next_date'];
            //else we need to get the highest index (the size)
            $vaccines[$i][$nextDateDoseNum]['datenext'] = $nextDateDoseExp;

            echo '';
        }


    }
    public static function mergeVaccines(&$vaccines, &$patientData){

        foreach ($vaccines as $index => $vaccine) {
            //if the index contains _proj, skip
            if(! strpos($index, '_proj') === false){
                continue;
            }
            //index is the industry name
            foreach ($vaccine as $d => $shot) {
                $patientData[strtolower(self::vaccineMap($index) . "_" . strval(intval($d) +1) . "_name")] = $shot['name'] . "(" . strval(intval($d) +1) . ")";
                $patientData[strtolower(self::vaccineMap($index) . "_" . strval(intval($d) +1)  . "_date")] = date('m/d/Y', strtotime($shot['date']) );
                $patientData[strtolower(self::vaccineMap($index) . "_" . strval(intval($d) +1)  . "_location")] = $shot['location'];
                $patientData[strtolower(self::vaccineMap($index) . "_" . strval(intval($d) +1)  . "_dose")] = $shot['dose'];
                $patientData[strtolower(self::vaccineMap($index) . "_" . strval(intval($d) +1)  . "_reaction")] = $shot['reaction'];

                if ($shot['datenext'] !== null) {
                    $patientData[strtolower(self::vaccineMap($index) . "_" . strval(intval($d) + 1) . "_datenext")] = date('m/d/Y', strtotime($shot['datenext']));
                }

                echo '';
            }
        }
        echo '';




    }


}
