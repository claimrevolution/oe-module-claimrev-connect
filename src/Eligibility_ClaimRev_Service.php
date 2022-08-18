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

    use OpenEMR\Common\Crypto\CryptoGen;
    use OpenEMR\Modules\ClaimRevConnector\EligibilityTransfer;

    function start_send_eligibility()
    {
        $cryptoGen = new CryptoGen();
        $clientId = $GLOBALS['oe_claimrev_config_clientid'];
        $client_secret = $cryptoGen->decryptStandard($GLOBALS['oe_claimrev_config_clientsecret']);
        $userName = $GLOBALS['oe_claimrev_config_username'];
        $password =  $cryptoGen->decryptStandard($GLOBALS['oe_claimrev_config_password']);
        $autoSend = $GLOBALS['oe_claimrev_send_eligibility'];

        if($autoSend)
        {
            EligibilityTransfer::sendWaitingEligibility($clientId,$client_secret, $userName, $password);

        }
        

    }

?>