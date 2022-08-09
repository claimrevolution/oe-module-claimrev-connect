<?php
namespace OpenEMR\Modules\ClaimRevConnector;

use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Modules\ClaimRevConnector\AuthoParam;
use OpenEMR\Modules\ClaimRevConnector\UploadEdiFileContentModel;

class ClaimRevApi
{
   
    //const OAUTH_URL="https://dev-e0h3yvwz.us.auth0.com/oauth/token";
    //const PORTAL_URL="https://9aad-174-128-131-22.ngrok.io";

    const OAUTH_URL="https://claimrevolution.us.auth0.com/oauth/token";
    const PORTAL_URL="https://api.claimrev.com";

    public static function GetAccessToken($clientId,$client_secret, $userName, $password) 
    {
        
        $param = new AuthoParam($clientId, $client_secret,$userName,$password);
        $headers = [
           'content-type: application/json'    
        ];

       
        $payload = json_encode($param, JSON_UNESCAPED_SLASHES);    

        $ch = curl_init();
        curl_setopt($ch,CURLOPT_URL, self::OAUTH_URL); 
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        echo "</br>";
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($result); 
        
        $token = "";
        if(property_exists($data,'access_token')){
            $token = $data->access_token;
        }
        return $token;
       
    }

    public static function uploadClaimFile($ediContents,$fileName, $token)
    {
        $content = 'content-type: application/json';
        $bearer = 'authorization: Bearer ' . $token;
        $headers = [
            $content,
            $bearer            
         ];     
         
        $url = SELF::PORTAL_URL . "/api/InputFile/v1";
       
        $model = new UploadEdiFileContentModel("",$ediContents,$fileName);
        $payload = json_encode($model, JSON_UNESCAPED_SLASHES);
       
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);         
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);      
        curl_close($ch);
        $data = json_decode($result);      

        if($httpcode != 200)
        {
            return false;
        }
       
        if($data->isError)
        {
            return false;
        }        
      
        return true;
    }

    public static function getReportFiles($reportType, $token)
    {
        $content = 'content-type: application/json';
        $bearer = 'authorization: Bearer ' . $token;
        $headers = [
            $content,
            $bearer            
         ];     
         
        $params = array('ediType' => $reportType);

        $endpoint = SELF::PORTAL_URL . "/api/EdiResponseFile/v1";
        $url = $endpoint . '?' . http_build_query($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
        
        curl_close($ch);
        if($httpcode != 200)
        {
            return "";
        }
        $data = json_decode($result);      

        return $data;
    }

    public static function searchClaims($claimSearch, $token)
    {
        $content = 'content-type: application/json';
        $bearer = 'authorization: Bearer ' . $token;
        $headers = [
            $content,
            $bearer            
         ];     
         
        $url = SELF::PORTAL_URL . "/api/ClaimView/v1/SearchClaims";
       
     
        $payload = json_encode($claimSearch, JSON_UNESCAPED_SLASHES);
       
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);         
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);      
        curl_close($ch);
        $data = json_decode($result);      

        if($httpcode != 200)
        {
            return false;
        }
       

        return $data;
    }

   
}

?>

