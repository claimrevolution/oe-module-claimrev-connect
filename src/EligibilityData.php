<?php
    namespace OpenEMR\Modules\ClaimRevConnector;
    use OpenEMR\Modules\ClaimRevConnector\ValueMapping;

    class EligibilityData
    {
        public function __construct() 
        { } 
        public static function GetPatientIdFromAppointment($eid)
        {
            $sql = "SELECT pc_pid from openemr_postcalendar_events WHERE pc_eid = ? LIMIT 1";
            $sqlarr = array($eid);
            $result = sqlStatement($sql,$sqlarr);  
            if(sqlNumRows($result)==1)
            {
                foreach ($result as $row)
                {
                    return $row["pc_pid"];
                }
            }
            return null;
        }
        public static function RemoveEligibilityCheck($pid,$payer_responsibility)
        {
            $sql = "DELETE FROM mod_claimrev_eligibility WHERE pid = ? AND payer_responsibility = ? ";
            $sqlarr = array($pid,$payer_responsibility);
            $result = sqlStatement($sql,$sqlarr);            
        }
        public static function getEligibilityCheckByStatus($status)
        {
            $sql = "SELECT * FROM mod_claimrev_eligibility WHERE status = ?";
            $sqlarr = array($status);

            $result = sqlStatement($sql,$sqlarr);   
            return $result; 
        }
        public static function getEligibilityResults($status, $minutes)
        {
            $sql = "SELECT * FROM mod_claimrev_eligibility WHERE status = ? AND TIMESTAMPDIFF(MINUTE,last_checked,NOW()) >= ?";
            $sqlarr = array($status,$minutes);            
            $result = sqlStatement($sql,$sqlarr); 
            return $result; 
        }
        public static function getEligibilityResult($pid,$payer_responsibility)
        {
            $pr = ValueMapping::MapPayerResponsibility($payer_responsibility);
            $sql = "SELECT status, coalesce(last_checked,create_date) as last_update,response_json FROM mod_claimrev_eligibility WHERE pid = ? AND payer_responsibility = ? LIMIT 1";
          
            $res = sqlStatement($sql, array($pid,$pr));   
            return $res;
        }

        public static function updateEligibilityRecord($id, $status,$request_json, $response_json, $updateLastChecked, $responseMessage,$raw271)
        {
            $sql = "UPDATE mod_claimrev_eligibility SET status = ? ";

            if($updateLastChecked)
            {
                $sql = $sql . ",last_checked = NOW() ";
            }
            if($response_json != null)
            {
                $sql = $sql . " ,response_json = '" . $response_json . "'";
            }      
            if($request_json != null)
            {
                $sql = $sql . " ,request_json = '" . $request_json . "'";
            }             
            if($responseMessage != null)
            {
                $sql = $sql . " ,response_message = '" . $responseMessage . "'";
            } 
  	    if($raw271 != null)
            {
                $sql = $sql . " ,raw271 = '" . $raw271 . "'";
            } 
            $sql = $sql . " WHERE id = ?";

            $sqlarr = array($status,$id);
            sqlStatement($sql,$sqlarr);

        }



        public static function getInsuranceData($pid=0,$pr = "")
        {
            $query = "SELECT
			i.type as payer_responsibility,
            d.facility_id,
            f.pos_code,
            c.name as payer_name,
            coalesce( c.eligibility_id, c.cms_id) as payerId,  
			f.facility_npi as facility_npi,
            f.name as facility_name,
            p.lname,
            p.fname,
            p.mname,
            DATE_FORMAT(p.dob, '%Y%m%d') as dob,
            p.ss,
            p.sex,
            p.pid,
            p.pubpid,
            p.providerID,
            i.subscriber_ss,
            i.policy_number,
            i.provider as payer_id,
            i.subscriber_relationship,
            i.subscriber_lname,
            i.subscriber_fname,
            i.subscriber_mname,
            DATE_FORMAT(i.subscriber_DOB, '%Y%m%d') as subscriber_dob,
            i.policy_number,
            i.subscriber_sex,
            DATE_FORMAT(i.date, '%Y%m%d') as date,
            d.lname as provider_lname,
            d.fname as provider_fname,
            d.npi as provider_npi,
            d.upin as provider_pin,
            f.federal_ein as federal_ein,     
            c.x12_default_eligibility_id as partner
			FROM patient_data AS p
			LEFT JOIN users AS d on 
				p.providerID = d.id
			INNER JOIN facility AS f on 
				f.id = d.facility_id
			INNER JOIN insurance_data AS i ON
				i.pid = p.id 
			LEFT JOIN insurance_companies as c ON (c.id = i.provider)
                WHERE p.pid = ? ";
            $ary = array($pid);

            if($pr != "")
            {
                $query = $query . "AND i.type = ?";
                array_push($ary,$pr);
            }
            $res = sqlStatement($query, $ary);      
           
            return $res;
        }
    }
    

?>
