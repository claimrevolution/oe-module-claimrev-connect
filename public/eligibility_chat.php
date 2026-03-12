<?php

/**
 * AJAX endpoint for AI eligibility chat.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Brad Sharp <brad.sharp@claimrev.com>
 * @copyright Copyright (c) 2022 Brad Sharp <brad.sharp@claimrev.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once "../../../../globals.php";

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Modules\ClaimRevConnector\ClaimRevApi;
use OpenEMR\Modules\ClaimRevConnector\ClaimRevException;

header('Content-Type: application/json');

if (!AclMain::aclCheckCore('acct', 'bill')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$sharpRevenueObjectId = $_POST['sharpRevenueObjectId'] ?? '';
$question = trim($_POST['question'] ?? '');
$payerCode = $_POST['payerCode'] ?? null;

if (empty($sharpRevenueObjectId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing sharpRevenueObjectId']);
    exit;
}

if (empty($question)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing question']);
    exit;
}

try {
    $api = ClaimRevApi::makeFromGlobals();
    $answer = $api->askEligibilityQuestion($sharpRevenueObjectId, $question, $payerCode);
    echo json_encode(['success' => true, 'answer' => $answer]);
} catch (ClaimRevException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to get AI response']);
}
