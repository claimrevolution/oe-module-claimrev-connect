<?php

/**
 * Executes the background service for billing, which sends EDI claims
 * directly to claimRev if enabled
 * 
 * @package   OpenEMR
 * @link      http://www.claimrev.com
 *
 * @author    Brad Sharp <brad.sharp@claimrev.com>
 * @copyright Copyright (c) 2022 Brad Sharp <brad.sharp@claimrev.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

use OpenEMR\Modules\ClaimRevConnector\ClaimUpload;
use OpenEMR\Modules\ClaimRevConnector\ReportDownload;
use OpenEMR\Common\Crypto\CryptoGen;

//require_once "ClaimUpload.php";
/**
 * This function is called by background services,
 * reads the x12_remote_tracker table and sends
 * files to x12 partners that are in the 'waiting'
 * status.
 */
function start_X12_Claimrev_send_files()
{
    
     $cryptoGen = new CryptoGen();
     $clientId = $GLOBALS['oe_claimrev_config_clientid'];
     $client_secret = $cryptoGen->decryptStandard($GLOBALS['oe_claimrev_config_clientsecret']);
     $userName = $GLOBALS['oe_claimrev_config_username'];
     $password =  $cryptoGen->decryptStandard($GLOBALS['oe_claimrev_config_password']);
     $autoSend = $GLOBALS['oe_claimrev_config_auto_send_claim_files'];

    
    // $clientId = $globalsConfig->getClientId();
    // $client_secret = $globalsConfig->getClientSecret();
    // $userName = $globalsConfig->getUserName();
    // $password = $globalsConfig->getPassword();
    // $autoSend = $globalsConfig->getAutoSendFiles();

    if ($autoSend) {
        ClaimUpload::sendWaitingFiles($clientId,$client_secret, $userName, $password); 
    }
}

function start_X12_Claimrev_get_reports()
{
    
     $cryptoGen = new CryptoGen();
     $clientId = $GLOBALS['oe_claimrev_config_clientid'];
     $client_secret = $cryptoGen->decryptStandard($GLOBALS['oe_claimrev_config_clientsecret']);
     $userName = $GLOBALS['oe_claimrev_config_username'];
     $password =  $cryptoGen->decryptStandard($GLOBALS['oe_claimrev_config_password']);
     $autoSend = $GLOBALS['oe_claimrev_config_auto_send_claim_files'];

    
    // $clientId = $globalsConfig->getClientId();
    // $client_secret = $globalsConfig->getClientSecret();
    // $userName = $globalsConfig->getUserName();
    // $password = $globalsConfig->getPassword();
    // $autoSend = $globalsConfig->getAutoSendFiles();

    if ($autoSend) {
        ReportDownload::getWaitingFiles($clientId,$client_secret, $userName, $password); 
    }
}