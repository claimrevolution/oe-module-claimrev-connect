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

require_once "../../../../globals.php";

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;

header('Content-Type: application/json');

if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

if (!AclMain::aclCheckCore('acct', 'bill')) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$id = $_POST['id'] ?? null;
if (empty($id)) {
    echo json_encode(['success' => false, 'message' => 'Missing tracker ID']);
    exit;
}

$sql = "UPDATE x12_remote_tracker SET status = 'waiting', messages = NULL, updated_at = NOW() WHERE id = ?";
$result = sqlStatement($sql, [$id]);

if ($result !== false) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database update failed']);
}
