<?php

/**
 * Mock service for generating fake ERA search results from real OpenEMR
 * billing data.
 *
 * Used to populate the ERA tab when no live ClaimRev backend is available
 * (demos, training, customer trials). Parallels PaymentAdviceMockService.
 *
 * The DB-bound generateMockResults() reads encounters via QueryUtils and
 * delegates to the pure buildRowFromEncounter() helper so the row-shaping
 * logic stays unit-testable in isolation.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Brad Sharp <brad.sharp@claimrev.com>
 * @copyright Copyright (c) 2026 Brad Sharp <brad.sharp@claimrev.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector;

use OpenEMR\Common\Database\QueryUtils;

class EraMockService
{
    /**
     * Build a single ERA search-result row from a pre-fetched encounter,
     * its billed total, and (optionally) its insurance lookup. Pure
     * function — no DB access. Tested in isolation.
     *
     * @param array<string, mixed> $enc Encounter row with pid, encounter, date.
     * @param float $billedAmt Sum of billing.fee for the encounter.
     * @param array<string, mixed>|null $insRow Insurance row with payer_name, payer_number, or null.
     */
    public static function buildRowFromEncounter(
        array $enc,
        float $billedAmt,
        ?array $insRow,
    ): \stdClass {
        $pid = TypeCoerce::asInt($enc['pid'] ?? 0);
        $encounter = TypeCoerce::asInt($enc['encounter'] ?? 0);
        $pcn = $pid . '-' . $encounter;

        // Deterministic 70-90% payer payment ratio. abs() guards against
        // crc32() returning a negative signed 32-bit value on 64-bit PHP,
        // which would otherwise let payRatio slip below 70%.
        $payRatio = (abs(crc32($pcn)) % 21 + 70) / 100;
        $payerPaidAmt = round($billedAmt * $payRatio, 2);
        $residual = $billedAmt - $payerPaidAmt;
        // 60% of residual is contractual write-off; 40% is patient resp.
        $writeOff = round($residual * 0.6, 2);
        $patientResp = round($residual - $writeOff, 2);

        $encounterDate = substr(TypeCoerce::asString($enc['date'] ?? ''), 0, 10);
        $receivedTs = strtotime($encounterDate . ' +7 days');
        $receivedDate = date(
            'Y-m-d',
            $receivedTs !== false ? $receivedTs : time(),
        );

        $payerName = is_array($insRow)
            ? TypeCoerce::asString($insRow['payer_name'] ?? 'Mock Payer')
            : 'Mock Payer';
        $payerNumber = is_array($insRow)
            ? TypeCoerce::asString($insRow['payer_number'] ?? '99999')
            : '99999';

        $row = new \stdClass();
        $row->id = md5('mock-era-' . $pid . '-' . $encounter);
        $row->receivedDate = $receivedDate . 'T00:00:00Z';
        $row->payerName = $payerName;
        $row->payerNumber = $payerNumber;
        $row->billedAmt = round($billedAmt, 2);
        $row->payerPaidAmt = $payerPaidAmt;
        $row->patientResponsibility = $patientResp;
        $row->downloadStatus = (crc32($pcn) % 2 === 0) ? 2 : 3;

        return $row;
    }

    /**
     * Filter a list of rows by their downloadStatus property.
     *
     * @param list<\stdClass> $rows
     * @return list<\stdClass>
     */
    public static function filterByDownloadStatus(array $rows, int $status): array
    {
        $kept = [];
        foreach ($rows as $row) {
            if ((int) $row->downloadStatus === $status) {
                $kept[] = $row;
            }
        }
        return $kept;
    }

    /**
     * Generate mock ERA search results from real OpenEMR billing data.
     *
     * Queries recent billed encounters within the requested date range,
     * sums each one's billing.fee for billedAmt, and delegates the row
     * shaping to buildRowFromEncounter. Filters the resulting list by
     * downloadStatus so the Waiting/Downloaded dropdown has effect.
     *
     * @param array{startDate?: string, endDate?: string, downloadStatus?: int|string} $filters
     * @return list<\stdClass>
     */
    public static function generateMockResults(array $filters): array
    {
        $startDate = TypeCoerce::asString($filters['startDate'] ?? '');
        $endDate = TypeCoerce::asString($filters['endDate'] ?? '');
        $downloadStatusRaw = $filters['downloadStatus'] ?? 0;
        $downloadStatus = is_numeric($downloadStatusRaw) ? (int) $downloadStatusRaw : 0;

        $where = ['b.billed = 1', 'b.activity = 1'];
        $params = [];

        if ($startDate !== '') {
            $where[] = 'e.date >= ?';
            $params[] = $startDate . ' 00:00:00';
        }
        if ($endDate !== '') {
            $where[] = 'e.date <= ?';
            $params[] = $endDate . ' 23:59:59';
        }

        $whereClause = implode(' AND ', $where);

        $sql = "SELECT DISTINCT e.pid, e.encounter, e.date " .
            "FROM form_encounter e " .
            "JOIN billing b ON b.pid = e.pid AND b.encounter = e.encounter " .
            "WHERE {$whereClause} " .
            "ORDER BY e.date DESC " .
            "LIMIT 50";
        $encounters = QueryUtils::fetchRecords($sql, $params);

        $rows = [];
        foreach ($encounters as $enc) {
            /** @var array<string, mixed> $enc */
            $pid = TypeCoerce::asInt($enc['pid'] ?? 0);
            $encounter = TypeCoerce::asInt($enc['encounter'] ?? 0);

            $billedAmt = TypeCoerce::asFloat(
                QueryUtils::fetchSingleValue(
                    'SELECT SUM(fee) AS total FROM billing ' .
                    'WHERE pid = ? AND encounter = ? AND activity = 1 AND fee > 0',
                    'total',
                    [$pid, $encounter],
                )
            );

            if ($billedAmt <= 0.0) {
                continue;
            }

            $insRowResult = QueryUtils::querySingleRow(
                'SELECT ic.name AS payer_name, ic.cms_id AS payer_number ' .
                'FROM insurance_data id ' .
                'JOIN insurance_companies ic ON ic.id = id.provider ' .
                "WHERE id.pid = ? AND id.type = 'primary' " .
                'ORDER BY id.date DESC LIMIT 1',
                [$pid],
            );
            // querySingleRow returns array<mixed>|false; normalize false to null
            // so PHPStan sees a real ?array type flowing into the helper.
            $insRow = is_array($insRowResult) ? $insRowResult : null;

            $rows[] = self::buildRowFromEncounter($enc, $billedAmt, $insRow);
        }

        if ($downloadStatus === 2 || $downloadStatus === 3) {
            $rows = self::filterByDownloadStatus($rows, $downloadStatus);
        }

        return $rows;
    }
}
