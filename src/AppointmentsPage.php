<?php

/**
 *
 * @package OpenEMR
 * @link    http://www.open-emr.org
 *
 * @author    Brad Sharp <brad.sharp@claimrev.com>
 * @copyright Copyright (c) 2022 Brad Sharp <brad.sharp@claimrev.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\ClaimRevConnector;

use OpenEMR\Modules\ClaimRevConnector\EligibilityData;
use OpenEMR\Modules\ClaimRevConnector\EligibilityObjectCreator;
use OpenEMR\Modules\ClaimRevConnector\ValueMapping;

class AppointmentsPage
{
    public static function getUpcomingAppointments($startDate, $endDate, $facilityId = null, $providerId = null)
    {
        $sql = "SELECT
                    e.pc_eid,
                    DATE_FORMAT(e.pc_eventDate, '%Y-%m-%d') as appointmentDate,
                    e.pc_startTime,
                    e.pc_pid,
                    e.pc_facility,
                    e.pc_aid,
                    e.pc_apptstatus,
                    e.pc_title,
                    p.fname,
                    p.lname,
                    p.mname,
                    DATE_FORMAT(p.dob, '%Y-%m-%d') as dob,
                    p.sex,
                    p.pid,
                    f.name as facility_name,
                    CONCAT(u.fname, ' ', u.lname) as provider_name,
                    elig.status as elig_status,
                    elig.payer_responsibility as elig_payer_responsibility,
                    coalesce(elig.last_checked, elig.create_date) as elig_last_checked,
                    elig.response_message as elig_response_message,
                    elig.eligibility_json as elig_eligibility_json,
                    elig.individual_json as elig_individual_json
                FROM openemr_postcalendar_events AS e
                LEFT JOIN patient_data AS p ON e.pc_pid = p.pid
                LEFT JOIN facility AS f ON e.pc_facility = f.id
                LEFT JOIN users AS u ON e.pc_aid = u.id
                LEFT JOIN mod_claimrev_eligibility AS elig ON (
                    elig.pid = e.pc_pid
                    AND elig.payer_responsibility = 'P'
                )
                WHERE e.pc_eventDate >= ?
                AND e.pc_eventDate <= ?";

        $sqlarr = array($startDate, $endDate);

        if ($facilityId != null && $facilityId != "") {
            $sql .= " AND e.pc_facility = ?";
            array_push($sqlarr, $facilityId);
        }

        if ($providerId != null && $providerId != "") {
            $sql .= " AND e.pc_aid = ?";
            array_push($sqlarr, $providerId);
        }

        $sql .= " ORDER BY e.pc_eventDate ASC, e.pc_startTime ASC";

        $result = sqlStatement($sql, $sqlarr);
        return $result;
    }

    public static function runEligibilityForAppointment($eid)
    {
        $appointmentData = EligibilityData::getPatientIdFromAppointment($eid);
        if ($appointmentData == null) {
            return;
        }

        $pid = $appointmentData['pc_pid'];
        $eventDate = $appointmentData['appointmentDate'];
        $facilityId = $appointmentData['facilityId'];
        $providerId = $appointmentData['providerId'];

        $insurance = EligibilityData::getInsuranceData($pid);
        foreach ($insurance as $row) {
            $pr = $row['payer_responsibility'];
            $formattedPr = ValueMapping::mapPayerResponsibility($pr);
            EligibilityData::removeEligibilityCheck($pid, $formattedPr);
            $requestObjects = EligibilityObjectCreator::buildObject($pid, $pr, $eventDate, $facilityId, $providerId);
            EligibilityObjectCreator::saveToDatabase($requestObjects, $pid);
        }
    }

    public static function getFacilities()
    {
        $sql = "SELECT id, name FROM facility WHERE service_location = 1 ORDER BY name";
        $result = sqlStatement($sql);
        return $result;
    }

    public static function getProviders()
    {
        $sql = "SELECT id, CONCAT(fname, ' ', lname) as provider_name
                FROM users
                WHERE authorized = 1
                AND active = 1
                AND npi IS NOT NULL
                AND npi != ''
                ORDER BY lname, fname";
        $result = sqlStatement($sql);
        return $result;
    }

    public static function getEligibilitySummary($eligJson)
    {
        if ($eligJson == null) {
            return null;
        }

        $individual = json_decode($eligJson);
        if ($individual == null || !property_exists($individual, 'eligibility')) {
            return null;
        }

        $results = [];
        foreach ($individual->eligibility as $eligibilityData) {
            $summary = new \stdClass();
            $summary->status = '';
            $summary->payerName = '';
            $summary->subscriberId = '';
            $summary->insuranceType = '';

            if (property_exists($eligibilityData, 'status')) {
                $summary->status = $eligibilityData->status;
            }
            if (property_exists($eligibilityData, 'payerInfo') && property_exists($eligibilityData->payerInfo, 'payerName')) {
                $summary->payerName = $eligibilityData->payerInfo->payerName;
            }
            if (property_exists($eligibilityData, 'subscriberId')) {
                $summary->subscriberId = $eligibilityData->subscriberId;
            }
            if (property_exists($eligibilityData, 'insuranceType')) {
                $summary->insuranceType = $eligibilityData->insuranceType;
            }

            $results[] = $summary;
        }

        return $results;
    }
}
