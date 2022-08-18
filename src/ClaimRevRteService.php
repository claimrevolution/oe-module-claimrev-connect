<?php 
namespace OpenEMR\Modules\ClaimRevConnector;

use OpenEMR\Modules\ClaimRevConnector\EligibilityData;
use OpenEMR\Modules\ClaimRevConnector\EligibilityInquiryRequest;
use OpenEMR\Modules\ClaimRevConnector\InformationReceiver;
use OpenEMR\Modules\ClaimRevConnector\SubscriberPatientEligibilityRequest;

class ClaimRevRteService
{

    public static function CreateEligibilityFromAppointment($eid)
    {
        $pid = EligibilityData::GetPatientIdFromAppointment($eid);
        if($pid != null)
        {
            $requestObjects = EligibilityObjectCreator::BuildObject($pid,"");
            EligibilityObjectCreator::SaveToDatabase($requestObjects,$pid );           
        }
    }

}
    