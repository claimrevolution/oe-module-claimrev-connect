<?php
namespace OpenEMR\Modules\ClaimRevConnector;
use OpenEMR\Modules\ClaimRevConnector\ClaimRevApi;
use OpenEMR\Common\Crypto\CryptoGen;

class ClaimSearch 
{
    public static function Search($search)
    {
        $cryptoGen = new CryptoGen();
        $clientId = $GLOBALS['oe_claimrev_config_clientid'];
        $client_secret = $cryptoGen->decryptStandard($GLOBALS['oe_claimrev_config_clientsecret']);
        $userName = $GLOBALS['oe_claimrev_config_username'];
        $password =  $cryptoGen->decryptStandard($GLOBALS['oe_claimrev_config_password']);

        $token = ClaimRevApi::GetAccessToken($clientId,$client_secret,$userName,$password);
        $data = ClaimRevApi::searchClaims($search,$token);

        return $data;
    }
}

?>