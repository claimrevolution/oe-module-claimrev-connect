<?php
    namespace OpenEMR\Modules\ClaimRevConnector;

    use OpenEMR\Services\BaseService;
    use OpenEMR\Modules\ClaimRevConnector\ClaimRevApi;
    use OpenEMR\Modules\ClaimRevConnector\EligibilityData;

    class EligibilityTransfer extends BaseService
    {
        const STATUS_WAITING = 'waiting';
        const STATUS_SUCCESS = 'success';
        const STATUS_SEND_ERROR = 'senderror';
        const STATUS_SEND_RETRY = 'retry';

        const TABLE_NAME = 'mod_claimrev_eligibility';

        public function __construct()
        {
            parent::__construct(self::TABLE_NAME);
        }

        public static function sendWaitingEligibility($clientId,$client_secret, $userName, $password)
        {
            $token = ClaimRevApi::GetAccessToken($clientId,$client_secret, $userName, $password); 
            $waitingEligibility = EligibilityData::getEligibilityCheckByStatus(self::STATUS_WAITING);
            EligibilityTransfer::sendEligibility($waitingEligibility,$token);

            $retryEligibility = EligibilityData::getEligibilityResults(self::STATUS_SEND_RETRY,60);
            EligibilityTransfer::retryEligibility($retryEligibility,$token);

        }
        public static function retryEligibility($retryEligibility,$token)
        {
            foreach ($retryEligibility as $eligibility) 
            {
                $eid = $eligibility['id'];                
                $result = ClaimRevApi::getEligibilityResult($eid,$token);   
                EligibilityTransfer::saveEligibility($result,$eid);
            }
        }
        public static function sendEligibility($waitingEligibility,$token)
        {
            foreach ($waitingEligibility as $eligibility) 
            {
                $eid = $eligibility['id'];                
                $request_json = $eligibility['request_json'];
                $elig = json_decode($request_json); 
                $result = ClaimRevApi::uploadEligibility($elig,$token);     
                EligibilityTransfer::saveEligibility($result,$eid);
            }
        }
        public static function saveEligibility($result,$eid)
        {
            if(false === $result)
            {
                EligibilityData::updateEligibilityRecord($eid, self::STATUS_SEND_ERROR,null,null,true,null);
                return;
            }               
            if (!property_exists($result, 'responseMessage')) 
            {
                EligibilityData::updateEligibilityRecord($eid, self::STATUS_SEND_ERROR,null,null,true,null);
                return;
            }     
            $responseMessage = $result->responseMessage;
            $raw271 = $result->raw271;
            if($result->retryLater)
            {
                EligibilityData::updateEligibilityRecord($eid, self::STATUS_SEND_RETRY,null,null,true,$responseMessage);
                return;
            }
            
            if($result->eligibilityResponse == null)
            {
                EligibilityData::updateEligibilityRecord($eid, self::STATUS_SEND_RETRY,null,null,true,$responseMessage);
                return;
            }
         
            $payload = json_encode($result->eligibilityResponse, JSON_UNESCAPED_SLASHES); 
            EligibilityData::updateEligibilityRecord($eid, self::STATUS_SUCCESS,null,$payload,true,$responseMessage);

        }
    }
?>
