<?php

/**
 * Background service that polls ClaimRev for account notifications
 * and creates pnotes in OpenEMR so users see them in their Messages inbox.
 *
 * Runs every 60 minutes. Tracks which notifications have already been
 * delivered via mod_claimrev_notifications to prevent duplicates.
 * Marks notifications as read on ClaimRev after delivery.
 *
 * @package OpenEMR
 * @link    http://www.claimrev.com
 *
 * @author    Brad Sharp <brad.sharp@claimrev.com>
 * @copyright Copyright (c) 2022 Brad Sharp <brad.sharp@claimrev.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

use OpenEMR\Modules\ClaimRevConnector\ClaimRevApi;
use OpenEMR\Modules\ClaimRevConnector\ClaimRevException;
use OpenEMR\Modules\ClaimRevConnector\GlobalConfig;

require_once($GLOBALS['fileroot'] . "/library/pnotes.inc.php");

function start_claimrev_notifications()
{
    $enabled = $GLOBALS[GlobalConfig::CONFIG_ENABLE_NOTIFICATIONS] ?? '1';
    if (!$enabled) {
        return;
    }

    try {
        $api = ClaimRevApi::makeFromGlobals();
    } catch (ClaimRevException) {
        return;
    }

    try {
        $notifications = $api->getPortalNotifications(false);
    } catch (ClaimRevException) {
        return;
    }

    if (!is_array($notifications)) {
        return;
    }

    $recipientSetting = $GLOBALS[GlobalConfig::CONFIG_NOTIFICATION_RECIPIENT] ?? 'admin';
    if ($recipientSetting == '') {
        $recipientSetting = 'admin';
    }
    $recipients = array_map('trim', explode(';', $recipientSetting));
    $recipients = array_filter($recipients, function ($v) {
        return $v !== '';
    });
    if (empty($recipients)) {
        $recipients = ['admin'];
    }

    foreach ($notifications as $notification) {
        $portalId = $notification['portalNotificationId'] ?? null;
        if ($portalId === null) {
            continue;
        }

        // Check if we already processed this notification
        $existing = sqlQuery(
            "SELECT id FROM mod_claimrev_notifications WHERE portal_notification_id = ?",
            array($portalId)
        );
        if ($existing) {
            continue;
        }

        $title = $notification['messageTitle'] ?? 'ClaimRev Notification';
        $body = $notification['messageBody'] ?? '';
        $messageText = "ClaimRev: " . $title . "\n\n" . $body;

        // Create pnote for each configured recipient
        $firstPnoteId = 0;
        foreach ($recipients as $recipient) {
            $pnoteId = addPnote(
                0,
                $messageText,
                0,
                1,
                "ClaimRev",
                $recipient,
                "",
                "New",
                "claimrev-notifications"
            );
            if ($firstPnoteId == 0) {
                $firstPnoteId = $pnoteId;
            }
        }

        // Track it so we don't create duplicates
        sqlInsert(
            "INSERT INTO mod_claimrev_notifications (portal_notification_id, message_title, message_body, pnote_id, created_date, processed_date) VALUES (?, ?, ?, ?, ?, NOW())",
            array(
                $portalId,
                $title,
                $body,
                $firstPnoteId,
                $notification['createdDate'] ?? date('Y-m-d H:i:s')
            )
        );

        // Mark as read on ClaimRev so it doesn't come back next poll
        try {
            $api->setNotificationReadStatus($portalId, true);
        } catch (ClaimRevException) {
            // Non-fatal - notification was already delivered
        }
    }
}
