<?php
/**
 * CAIR specific extensions to standard SoapClient
 *
 * Copyright (C) 2025-2026 MD Support <mdsupport@users.sourceforge.net>
 *
 * @package   Mdvxu
 * @author    MD Support <mdsupport@users.sourceforge.net>
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace Mdsupport\Mdvxu\IR;

class SoapClientCA extends \SoapClient
{
    private $msg;
    private $wsdlUrl;
    
    /**
     * CAIR specific SoapClient array of options
     * @param array $aOpts - Associative array with values required for following keys - 'username', 'password', 'facilityID', 'wsdlUrl'. 
     * Also respects any standard SoapClient options specified.
     */
    function __construct(array $aOpts) {
        // Extract 3 keys needed in every message
        $this->msg = array_intersect_key($aOpts, array_flip(['username', 'password', 'facilityID']));
        $this->wsdlUrl = $aOpts['wsdlUrl'];
        $aOpts = array_diff_key($aOpts, $this->msg, ['wsdlUrl' => null]);
        $aOpts = array_merge($this->defaultSoapClientOptions(), $aOpts);
        parent::__construct($this->wsdlUrl, $aOpts);
    }

    private function defaultSoapClientOptions()
    {
        $streamContext = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];
        $streamContext = stream_context_create($streamContext);
        
        return [
            'stream_context' => $streamContext,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'soap_version' => SOAP_1_2,
        ];
    }
    
    /**Add specified string to other elements provided to the constructor and submit message.
     *
     * @param string $message
     * @return array Result
     */
    public function submitSingleMessage($message)
    {
        // Keep full copy so in future last message can be retrieved
        $this->msg['hl7Message'] = $message;
        //submitSingleMessage connectivityTest
        try {
            return parent::submitSingleMessage($this->msg);
        } catch (Exception $e) {
            echo $e->getMessage();
            exit;
        }
    }
}
