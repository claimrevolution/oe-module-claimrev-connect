<?php

/**
 *
 * @package OpenEMR
 * @link    https://www.open-emr.org
 *
 * @author    Brad Sharp <brad.sharp@claimrev.com>
 * @copyright Copyright (c) 2022 Brad Sharp <brad.sharp@claimrev.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\ClaimRevConnector;

use OpenEMR\Core\OEGlobalsBag;

class ClaimRevModuleSetup
{
    public function __construct()
    {
    }

    public static function doesPartnerExists()
    {
        $x12Name = OEGlobalsBag::getInstance()->get('oe_claimrev_x12_partner_name');
        $sql = "SELECT * FROM x12_partners WHERE name = ?";
        $sqlarr = [$x12Name];
        $result = sqlStatementNoLog($sql, $sqlarr);
        $rowCount = sqlNumRows($result);

        if ($rowCount > 0) {
            return true;
        }
        return false;
    }
    /**
     * Create the X12 partner record for ClaimRev.
     *
     * Populates the ISA/GS fields per the ClaimRev companion guide:
     * - ISA05/07: ZZ
     * - ISA08/GS03/x12_receiver_id: CLAIMREV
     * - ISA15: P (Production)
     * - Processing format: standard
     */
    public static function createPartnerRecord(string $idNumber = '', string $senderId = '')
    {
        $x12Name = OEGlobalsBag::getInstance()->get(GlobalConfig::CONFIG_X12_PARTNER_NAME) ?: 'ClaimRev';

        // Don't create if it already exists
        if (self::doesPartnerExists()) {
            return;
        }

        // Get the next available ID since x12_partners.id is not auto-increment
        $row = sqlQuery("SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM x12_partners");
        $nextId = $row['next_id'];

        $sql = "INSERT INTO x12_partners (
            id, name, id_number,
            x12_sender_id, x12_receiver_id,
            processing_format,
            x12_isa01, x12_isa02, x12_isa03, x12_isa04,
            x12_isa05, x12_isa07, x12_isa14, x12_isa15,
            x12_gs02, x12_gs03,
            x12_per06, x12_dtp03
        ) VALUES (
            ?, ?, ?,
            ?, 'CLAIMREV',
            'standard',
            '00', '          ', '00', '          ',
            'ZZ', 'ZZ', '0', 'P',
            ?, 'CLAIMREV',
            '', 'A'
        )";

        sqlStatement($sql, [$nextId, $x12Name, $idNumber, $senderId, $senderId]);
    }

    public static function couldSftpServiceCauseIssues()
    {
        $sftp = ClaimRevModuleSetup::getServiceRecord("X12_SFTP");
        if ($sftp != null) {
            if ($sftp["active"] == 1) {
                if ($sftp["require_once"] == "/library/billing_sftp_service.php") {
                    return true;
                }
            }
        }
        return false;
    }
    public static function deactivateSftpService()
    {
        $require_once = "/interface/modules/custom_modules/oe-module-claimrev-connect/src/SFTP_Mock_Service.php";
        ClaimRevModuleSetup::updateBackGroundServiceSetRequireOnce("X12_SFTP", $require_once);
    }
    public static function reactivateSftpService()
    {
        $require_once = "/library/billing_sftp_service.php";
        ClaimRevModuleSetup::updateBackGroundServiceSetRequireOnce("X12_SFTP", $require_once);
    }
    public static function updateBackGroundServiceSetRequireOnce($name, $requireOnce)
    {
        $sql = "UPDATE background_services SET require_once = ? WHERE name = ?";
        $sqlarr = [$requireOnce,$name];
        sqlStatement($sql, $sqlarr);
    }
    public static function getServiceRecord($name)
    {
        $sql = "SELECT * FROM background_services WHERE name = ? LIMIT 1";
        $sqlarr = [$name];
        $result = sqlStatement($sql, $sqlarr);
        if (sqlNumRows($result) == 1) {
            foreach ($result as $row) {
                return $row;
            }
        }
        return null;
    }
    /**
     * Reset any ClaimRev background services that are stuck in running state.
     * If running = 1 and next_run is more than 10 minutes in the past,
     * the service is stuck (PHP crash, OOM kill, etc.) and needs to be freed.
     */
    public static function resetStuckServices()
    {
        $sql = "UPDATE background_services SET running = 0 WHERE running = 1 AND next_run < (NOW() - INTERVAL 10 MINUTE) AND name LIKE '%ClaimRev%'";
        sqlStatementNoLog($sql);
    }

    /**
     * Run our module's table.sql directly without the core SQLUpgradeService,
     * which fires events that trigger unrelated core upgrade scripts.
     *
     * Supports: CREATE/INSERT/ALTER/UPDATE/DELETE statements,
     * #IfNotRow, #IfNotColumnType, #IfNotTable, #EndIf directives.
     */
    public static function runMigrations()
    {
        $modulePath = dirname(__DIR__);
        $fullname = $modulePath . '/table.sql';
        $fd = fopen($fullname, 'r');
        if ($fd === false) {
            return;
        }

        $query = '';
        $skipping = false;

        while (!feof($fd)) {
            $line = fgets($fd, 2048);
            $line = rtrim($line);

            if (preg_match('/^\s*--/', $line) || $line === '') {
                continue;
            }

            if (preg_match('/^#IfNotRow\s+(\S+)\s+(\S+)\s+(.+)/', $line, $matches)) {
                $row = sqlQuery("SELECT * FROM `" . $matches[1] . "` WHERE `" . $matches[2] . "` = ?", [trim($matches[3])]);
                $skipping = !empty($row);
                continue;
            } elseif (preg_match('/^#IfNotTable\s+(\S+)/', $line, $matches)) {
                $row = sqlQuery("SHOW TABLES LIKE ?", [$matches[1]]);
                $skipping = !empty($row);
                continue;
            } elseif (preg_match('/^#IfNotColumnType\s+(\S+)\s+(\S+)\s+(\S+)/', $line, $matches)) {
                $row = sqlQuery("SHOW COLUMNS FROM `" . $matches[1] . "` WHERE Field = ?", [$matches[2]]);
                $skipping = ($row && stripos($row['Type'], $matches[3]) !== false);
                continue;
            } elseif (preg_match('/^#(EndIf|Endif)/i', $line)) {
                $skipping = false;
                continue;
            } elseif (preg_match('/^#/', $line)) {
                continue;
            }

            if ($skipping) {
                continue;
            }

            $query .= $line;
            if (preg_match('/;\s*$/', $query)) {
                $query = rtrim($query, "; \t\n\r");
                if (!empty(trim($query))) {
                    sqlStatementNoLog($query);
                }
                $query = '';
            }
        }

        fclose($fd);
    }

    public static function getBackgroundServices()
    {
        $sql = "SELECT * FROM background_services WHERE name like '%ClaimRev%' OR name = 'X12_SFTP'";
        $result = sqlStatement($sql);
        return $result;
    }
    public static function createBackGroundServices()
    {
        $sql = "DELETE FROM background_services WHERE name like '%ClaimRev%'";
        sqlStatement($sql);

        $sql = "INSERT INTO `background_services` (`name`, `title`, `active`, `running`, `next_run`, `execute_interval`, `function`, `require_once`, `sort_order`) VALUES
            ('ClaimRev_Send', 'Send Claims To ClaimRev', 1, 0, '2017-05-09 17:39:10', 1, 'start_X12_Claimrev_send_files', '/interface/modules/custom_modules/oe-module-claimrev-connect/src/Billing_Claimrev_Service.php', 100);";
        sqlStatement($sql);

        $sql = "INSERT INTO `background_services` (`name`, `title`, `active`, `running`, `next_run`, `execute_interval`, `function`, `require_once`, `sort_order`) VALUES
            ('ClaimRev_Receive', 'Get Reports from ClaimRev', 1, 0, '2017-05-09 17:39:10', 240, 'start_X12_Claimrev_get_reports', '/interface/modules/custom_modules/oe-module-claimrev-connect/src/Billing_Claimrev_Service.php', 100);";
        sqlStatement($sql);

        $sql = "INSERT INTO `background_services` (`name`, `title`, `active`, `running`, `next_run`, `execute_interval`, `function`, `require_once`, `sort_order`) VALUES
            ('ClaimRev_Elig_Send_Receive', 'Send and Receive Eligibility from ClaimRev', 1, 0, '2017-05-09 17:39:10', 1, 'start_send_eligibility', '/interface/modules/custom_modules/oe-module-claimrev-connect/src/Eligibility_ClaimRev_Service.php', 100);";
        sqlStatement($sql);

        $sql = "INSERT INTO `background_services` (`name`, `title`, `active`, `running`, `next_run`, `execute_interval`, `function`, `require_once`, `sort_order`) VALUES
            ('ClaimRev_Notifications', 'ClaimRev Notification Check', 1, 0, '2017-05-09 17:39:10', 60, 'start_claimrev_notifications', '/interface/modules/custom_modules/oe-module-claimrev-connect/src/ClaimRev_Notification_Service.php', 100);";
        sqlStatement($sql);

        $sql = "INSERT INTO `background_services` (`name`, `title`, `active`, `running`, `next_run`, `execute_interval`, `function`, `require_once`, `sort_order`) VALUES
            ('ClaimRev_Watchdog', 'ClaimRev Stuck Service Watchdog', 1, 0, '2017-05-09 17:39:10', 20, 'start_claimrev_watchdog', '/interface/modules/custom_modules/oe-module-claimrev-connect/src/ClaimRev_Watchdog_Service.php', 50);";
        sqlStatement($sql);
    }
}
