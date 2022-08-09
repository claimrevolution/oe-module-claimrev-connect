<?php

namespace OpenEMR\Modules\ClaimRevConnector;

use OpenEMR\Services\BaseService;
use OpenEMR\Modules\ClaimRevConnector\ClaimRevApi;


class ReportDownload extends BaseService
{

    public static function getWaitingFiles($clientId,$client_secret, $userName, $password)
    {
        $reportTypes = array("999", "277", "835");
        $siteDir = $GLOBALS['OE_SITE_DIR'];
        //should be something like '/var/www/localhost/htdocs/openemr/sites/default'  
           
        $token = ClaimRevApi::GetAccessToken($clientId,$client_secret, $userName, $password); 
        foreach($reportTypes as $reportType) 
        {
            $reportFolder = "f". $reportType;
            if($reportType == "999")
            {
                $reportFolder = "f997";
            }
            
            $savePath = $siteDir . '/documents/edi/history/' . $reportFolder . '/';
           
            //$savePath = $siteDir . '/documents/edi/';
            if (!file_exists($savePath)) {
  
                // Create a direcotry
                mkdir($savePath, 0777, true);
            }
                          
            $datas = ClaimRevApi::getReportFiles($reportType,$token);
            if(is_array($datas))
            {
                foreach($datas as $data)
                {
                    if(property_exists($data,'fileText'))
                    {
                        $fileText = $data->fileText;
                        $fileName = $data->fileName ;
                        $filePathName =  $savePath . $fileName . '.txt';
                        file_put_contents($filePathName,$fileText);
                        chmod($filePathName, 0777);
                    }
                    else
                    {
                        error_log("Unable to find property FileText in ClaimRevConnector.ReportDownload.getWaitingFiles ");
                    }
                } 
            }



        }
    }
}




?>
