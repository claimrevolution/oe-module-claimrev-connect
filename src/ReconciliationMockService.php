<?php

/**
 * Mock service for generating fake Reconciliation rows from real OpenEMR
 * billing data.
 *
 * Parallels EraMockService. Synthesizes the ClaimRev side of each row
 * with a deterministic 80/15/5 split: 80% matched-clean, 15% matched
 * with a fake discrepancy, 5% not found in ClaimRev. The pure
 * buildEncounterRow() helper is isolated-testable; reconcile() does the
 * DB plumbing.
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

class ReconciliationMockService
{
    /**
     * Build a single reconciliation row from a pre-fetched encounter,
     * its billed total, and (optionally) its primary insurance lookup.
     *
     * Pure — no DB access. Outcome bucket derived deterministically from
     * the encounter's PCN ("$pid-$encounter") so the same encounter always
     * lands in the same bucket across reloads.
     *
     * @param array<string, mixed> $enc Encounter row with pid, encounter, date, fname, lname, DOB, oeStatus, oeStatusLabel, billTime, oeProcessFile.
     * @param float $billedAmt Sum of billing.fee for the encounter.
     * @param array<string, mixed>|null $insRow Insurance row with payer_name, payer_number, or null.
     * @return array<string, mixed>
     */
    public static function buildEncounterRow(
        array $enc,
        float $billedAmt,
        ?array $insRow,
    ): array {
        $pid = TypeCoerce::asInt($enc['pid'] ?? 0);
        $encounter = TypeCoerce::asInt($enc['encounter'] ?? 0);
        $pcn = $pid . '-' . $encounter;

        $bucket = abs(crc32($pcn)) % 100;

        // 0..79 clean matched, 80..94 discrepancy, 95..99 not found.
        $crFound = $bucket < 95;
        $hasDiscrepancy = $bucket >= 80 && $bucket < 95;

        $payerName = is_array($insRow)
            ? TypeCoerce::asString($insRow['payer_name'] ?? 'Mock Payer')
            : 'Mock Payer';
        $payerNumber = is_array($insRow)
            ? TypeCoerce::asString($insRow['payer_number'] ?? '99999')
            : '99999';

        $payRatio = (abs(crc32($pcn)) % 21 + 70) / 100;
        $cleanPaidAmt = round($billedAmt * $payRatio, 2);

        $crObjectId = md5('mock-recon-' . $pid . '-' . $encounter);

        // Derived ClaimRev fields by bucket.
        if (!$crFound) {
            $crStatusId = 0;
            $crStatusName = '';
            $crPayerAcceptance = '';
            $crPayerAcceptanceStatusId = 0;
            $crEraClassification = '';
            $crPayerPaidAmount = 0.0;
            $crIsWorked = false;
            $crObjectId = '';
            $discrepancy = '';
            $discrepancyLevel = '';
        } elseif ($hasDiscrepancy) {
            $crStatusId = 10; // Rejected — matches reconciliation.php's danger-status list
            $crStatusName = 'Rejected';
            $crPayerAcceptance = 'Rejected';
            $crPayerAcceptanceStatusId = 3;
            $crEraClassification = 'Denied';
            $crPayerPaidAmount = 0.0;
            $crIsWorked = false;
            $discrepancy = 'ClaimRev marked denied; OE still billed';
            $discrepancyLevel = 'danger';
        } else {
            $crStatusId = 7; // Submitted / In Process
            $crStatusName = 'Paid';
            $crPayerAcceptance = 'Accepted';
            $crPayerAcceptanceStatusId = 4;
            $crEraClassification = 'Paid';
            $crPayerPaidAmount = $cleanPaidAmt;
            $crIsWorked = true;
            $discrepancy = '';
            $discrepancyLevel = '';
        }

        $encounterDate = substr(TypeCoerce::asString($enc['date'] ?? ''), 0, 10);

        return [
            'patientName' => trim(
                TypeCoerce::asString($enc['fname'] ?? '') . ' '
                . TypeCoerce::asString($enc['lname'] ?? '')
            ),
            'patientDob' => TypeCoerce::asString($enc['DOB'] ?? ''),
            'pcn' => $pcn,
            'encounterDate' => $encounterDate,
            'payerName' => $payerName,
            'payerNumber' => $payerNumber,
            'totalCharges' => round($billedAmt, 2),
            'pid' => $pid,
            'encounter' => $encounter,
            'oeStatus' => TypeCoerce::asInt($enc['oeStatus'] ?? 0),
            'oeStatusLabel' => TypeCoerce::asString($enc['oeStatusLabel'] ?? ''),
            'billTime' => TypeCoerce::asString($enc['billTime'] ?? ''),
            'oeProcessFile' => TypeCoerce::asString($enc['oeProcessFile'] ?? ''),
            'crFound' => $crFound,
            'crStatusId' => $crStatusId,
            'crStatusName' => $crStatusName,
            'crPayerAcceptance' => $crPayerAcceptance,
            'crPayerAcceptanceStatusId' => $crPayerAcceptanceStatusId,
            'crEraClassification' => $crEraClassification,
            'crPayerPaidAmount' => $crPayerPaidAmount,
            'crIsWorked' => $crIsWorked,
            'crObjectId' => $crObjectId,
            'discrepancy' => $discrepancy,
            'discrepancyLevel' => $discrepancyLevel,
        ];
    }

    /**
     * Generate mock reconciliation results from real OpenEMR billing data.
     *
     * Queries billed encounters within the requested date range, sums each
     * encounter's billing.fee, looks up the primary insurance, and delegates
     * row shaping to buildEncounterRow.
     *
     * Honors patient last name and payer name filters on the SQL side, the
     * date range, and the discrepancyOnly filter as a post-build pass.
     *
     * @param array{dateStart?: string, dateEnd?: string, patientLastName?: string, payerName?: string, discrepancyOnly?: string, pageIndex?: int} $filters
     * @return array{encounters: list<array<string, mixed>>, totalRecords: int, claimRevLookupFailed: bool}
     */
    public static function reconcile(array $filters): array
    {
        $dateStart = TypeCoerce::asString($filters['dateStart'] ?? '');
        $dateEnd = TypeCoerce::asString($filters['dateEnd'] ?? '');
        $patientLastName = TypeCoerce::asString($filters['patientLastName'] ?? '');
        $payerName = TypeCoerce::asString($filters['payerName'] ?? '');
        $discrepancyOnly = TypeCoerce::asString($filters['discrepancyOnly'] ?? '') === '1';
        $pageIndex = TypeCoerce::asInt($filters['pageIndex'] ?? 0);
        $pageSize = 50;
        $offset = $pageIndex * $pageSize;

        $where = ['b.billed = 1', 'b.activity = 1'];
        $params = [];

        if ($dateStart !== '') {
            $where[] = 'e.date >= ?';
            $params[] = $dateStart . ' 00:00:00';
        }
        if ($dateEnd !== '') {
            $where[] = 'e.date <= ?';
            $params[] = $dateEnd . ' 23:59:59';
        }
        if ($patientLastName !== '') {
            $where[] = 'p.lname LIKE ?';
            $params[] = '%' . $patientLastName . '%';
        }

        $whereClause = implode(' AND ', $where);

        $sql = "SELECT DISTINCT e.pid, e.encounter, e.date, p.fname, p.lname, p.DOB " .
            "FROM form_encounter e " .
            "JOIN patient_data p ON p.pid = e.pid " .
            "JOIN billing b ON b.pid = e.pid AND b.encounter = e.encounter " .
            "WHERE {$whereClause} " .
            "ORDER BY e.date DESC " .
            "LIMIT {$pageSize} OFFSET {$offset}";
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
            $insRow = is_array($insRowResult) ? $insRowResult : null;

            // Inject the OE-side fields buildEncounterRow expects. For a
            // mock, oeStatus stays at 2 (Billed) — the real bill_process
            // mapping isn't worth duplicating here.
            $enc['oeStatus'] = 2;
            $enc['oeStatusLabel'] = 'Billed';
            $enc['billTime'] = TypeCoerce::asString($enc['date'] ?? '');
            $enc['oeProcessFile'] = 'mock-batch.x12';

            $row = self::buildEncounterRow($enc, $billedAmt, $insRow);

            // Apply the payer-name filter post-build (mock payer is derived
            // from the real insurance row).
            if ($payerName !== '' && stripos($row['payerName'], $payerName) === false) {
                continue;
            }

            $rows[] = $row;
        }

        if ($discrepancyOnly) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $r): bool => $r['discrepancy'] !== '',
            ));
        }

        return [
            'encounters' => $rows,
            'totalRecords' => count($rows),
            'claimRevLookupFailed' => false,
        ];
    }
}
