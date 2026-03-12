<?php

/**
 * AJAX endpoint for real-time eligibility check on a single appointment.
 *
 * Looks up the appointment, gets patient/insurance data, sends the eligibility
 * request to ClaimRev immediately and returns the result.
 *
 * @package OpenEMR
 * @link    http://www.open-emr.org
 *
 * @author    Brad Sharp <brad.sharp@claimrev.com>
 * @copyright Copyright (c) 2022 Brad Sharp <brad.sharp@claimrev.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once "../../../../globals.php";

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Modules\ClaimRevConnector\EligibilityData;
use OpenEMR\Modules\ClaimRevConnector\EligibilityTransfer;
use OpenEMR\Modules\ClaimRevConnector\ValueMapping;

header('Content-Type: application/json');

if (!AclMain::aclCheckCore('acct', 'bill')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$eid = $_POST['eid'] ?? '';

if (empty($eid)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing appointment ID']);
    exit;
}

$appointmentData = EligibilityData::getPatientIdFromAppointment($eid);
if ($appointmentData == null) {
    echo json_encode(['success' => false, 'message' => 'Appointment not found']);
    exit;
}

$pid = $appointmentData['pc_pid'];
$eventDate = $appointmentData['appointmentDate'];
$facilityId = $appointmentData['facilityId'];
$providerId = $appointmentData['providerId'];

// Run for all insurance types the patient has
$insurance = EligibilityData::getInsuranceData($pid);
$lastResult = ['success' => false, 'message' => 'No insurance found'];

foreach ($insurance as $row) {
    $pr = $row['payer_responsibility'];
    $lastResult = EligibilityTransfer::sendImmediate(
        $pid,
        $pr,
        [1], // Default to eligibility product for appointment checks
        $eventDate,
        $facilityId,
        $providerId
    );
}

echo json_encode($lastResult);
