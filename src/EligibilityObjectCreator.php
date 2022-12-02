<?php 
namespace OpenEMR\Modules\ClaimRevConnector;

use OpenEMR\Modules\ClaimRevConnector\EligibilityData;
use OpenEMR\Modules\ClaimRevConnector\EligibilityInquiryRequest;
use OpenEMR\Modules\ClaimRevConnector\InformationReceiver;
use OpenEMR\Modules\ClaimRevConnector\SubscriberPatientEligibilityRequest;

class EligibilityObjectCreator
{
    
    public static function BuildObject($pid,$pr)
    {
        $useFacility = $GLOBALS['oe_claimrev_config_use_facility_for_eligibility'];
        $serviceTypeCodes = $GLOBALS['oe_claimrev_config_service_type_codes'];
        $objects = array();

        $res = EligibilityData::getInsuranceData($pid,$pr);
        foreach ($res as $row)
        {
            $subscriber = new SubscriberPatientEligibilityRequest();
            $subscriber->firstName = $row['subscriber_fname'];
            $subscriber->lastName = $row['subscriber_lname'];
            $subscriber->middleName = $row['subscriber_mname'];
            $subscriber->memberId = $row['policy_number'];
            $subscriber->dateOfBirth = $row['subscriber_dob'];
            $subscriber->gender = $row['subscriber_sex'];

           

            $patient = new SubscriberPatientEligibilityRequest();
            $patient->firstName = $row['fname'];
            $patient->lastName = $row['lname'];
            $patient->middleName = $row['mname'];           
            $patient->dateOfBirth = $row['dob'];
            $patient->gender = $row['sex'];
            $patient->memberId = $row['policy_number'];

            $provider = new InformationReceiver();
            if($useFacility)
            {
                $provider->receiverType = "FA";
                $provider->firstName = "";
                $provider->groupName = $row['facility_name'];
                $provider->providerNpi = $row['facility_npi'];
            }
            else 
            {
                $provider->receiverType = "1P";
                $provider->firstName = $row['provider_fname'];
                $provider->lastName = $row['provider_lname'];
                $provider->providerNpi = $row['provider_npi'];
                $provider->signature = $row['provider_lname'] + ", " $row['provider_fname'];
            }



            $request = new EligibilityInquiryRequest($subscriber,$patient,$row['subscriber_relationship'],$row['payer_responsibility']);    
            $request->payerNumber = $row['payerId'];
            $request->payerName = $row['payer_name'];
            $request->provider = $provider;
            $request->industryCode = $row['pos_code'];
            $request->serviceTypeCodes = explode(",",$serviceTypeCodes);

            array_push($objects,$request);
        }
        return $objects;
    }
    public static function SaveSingleToDatabase($req,$pid)
    {
        
        $stale_age = $GLOBALS['oe_claimrev_eligibility_results_age'];
        //status of re-check if results are still waiting on claimrev site

        //if it's greater than aged date then lets remove completely from the tables, the new one will handle it. We don't care about statuses
        $sql = "DELETE FROM mod_claimrev_eligibility WHERE pid = ? AND payer_responsibility = ? AND (datediff(now(),create_date) >= ? or status in('error','waiting','creating') ) ";
        $sqlarr = array($pid,$req->payerResponsibility, $stale_age);
        $result = sqlStatement($sql,$sqlarr);

        $sql = "SELECT * FROM mod_claimrev_eligibility WHERE pid = ? AND payer_responsibility = ?";
        $sqlarr = array($pid,$req->payerResponsibility);
        $result = sqlStatement($sql,$sqlarr);
        if(sqlNumRows($result)<=0)
        {
            $status = "creating";
            $sql = "INSERT INTO mod_claimrev_eligibility (pid,payer_responsibility,status,create_date) VALUES(?,?,?,NOW())";
            $sqlarr = array($pid,$req->payerResponsibility,$status);
            $result = sqlInsert($sql,$sqlarr);
            $status = "waiting";
        
            $req->originatingSystemId = $result;
            $json = json_encode($req,true);
            $sql = "UPDATE mod_claimrev_eligibility SET request_json = '". $json ."', status = ? where id = ?";
            $sqlarr = array($status,$result);
            sqlStatement($sql,$sqlarr);
            }
    }
    public static function SaveToDatabase($requests,$pid)
    {
        //oe_claimrev_eligibility_results_age
        //lets check for status for waiting or error and replace the json and reset-status, what to do if inprogress??

        foreach ($requests as $req)
        {
            EligibilityObjectCreator::SaveSingleToDatabase($req,$pid);
        }
        
    }
}
?>
