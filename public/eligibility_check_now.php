<?php

/**
 * AJAX endpoint for real-time eligibility checking.
 *
 * Sends the eligibility request to ClaimRev immediately and returns the result.
 * Works for any product: Eligibility, Demographics, Coverage Discovery, MBI Finder.
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
use OpenEMR\Modules\ClaimRevConnector\EligibilityTransfer;

header('Content-Type: application/json');

if (!AclMain::aclCheckCore('acct', 'bill')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$pid = $_POST['pid'] ?? '';
$responsibility = $_POST['responsibility'] ?? '';

if (empty($pid) || empty($responsibility)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing pid or responsibility']);
    exit;
}

// Collect selected products
$selectedProducts = [];
if (!empty($_POST['product_1'])) {
    $selectedProducts[] = 1;
}
if (!empty($_POST['product_2'])) {
    $selectedProducts[] = 2;
}
if (!empty($_POST['product_3'])) {
    $selectedProducts[] = 3;
}
if (!empty($_POST['product_5'])) {
    $selectedProducts[] = 5;
}
if (empty($selectedProducts)) {
    $selectedProducts = [1];
}

$eventDate = !empty($_POST['eventDate']) ? $_POST['eventDate'] : null;
$facilityId = !empty($_POST['facilityId']) ? $_POST['facilityId'] : null;
$providerId = !empty($_POST['providerId']) ? $_POST['providerId'] : null;

$result = EligibilityTransfer::sendImmediate(
    $pid,
    $responsibility,
    $selectedProducts,
    $eventDate,
    $facilityId,
    $providerId
);

echo json_encode($result);
