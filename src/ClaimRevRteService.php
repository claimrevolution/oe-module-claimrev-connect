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
        $row = EligibilityData::GetPatientIdFromAppointment($eid);
        if($row != null)
        {
            $pid = $row["pc_pid"];
            $appointmentDate = $row["appointmentDate"];
            $facilityId = $row["facilityId"];
            $providerId = $row["providerId"];

            $requestObjects = EligibilityObjectCreator::BuildObject($pid,"",$appointmentDate, $facilityId, $providerId);
            EligibilityObjectCreator::SaveToDatabase($requestObjects,$pid );           
        }
    }

}
    