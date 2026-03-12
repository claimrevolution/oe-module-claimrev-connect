<?php

/**
 * AJAX endpoint to toggle claim worked status.
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
use OpenEMR\Modules\ClaimRevConnector\ClaimRevApi;
use OpenEMR\Modules\ClaimRevConnector\ClaimRevException;

header('Content-Type: application/json');

if (!AclMain::aclCheckCore('acct', 'bill')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$objectId = $_POST['objectId'] ?? '';
$isWorked = ($_POST['isWorked'] ?? '0') === '1';

if (empty($objectId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing objectId']);
    exit;
}

try {
    $api = ClaimRevApi::makeFromGlobals();
    $result = $api->markClaimAsWorked($objectId, $isWorked);
    echo json_encode(['success' => $result, 'isWorked' => $isWorked]);
} catch (ClaimRevException) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'API call failed']);
}
