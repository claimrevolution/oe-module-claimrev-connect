<?php

/**
 * Claims search page for ClaimRev integration
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Brad Sharp <brad.sharp@claimrev.com>
 * @copyright Copyright (c) 2022 Brad Sharp <brad.sharp@claimrev.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector;

use OpenEMR\Modules\ClaimRevConnector\Dto\ClaimSearchResult;

class ClaimsPage
{
    public function __construct(private readonly ClaimRevApi $api)
    {
    }

    /**
     * @param array<string, mixed> $postData
     * @return array{results: list<ClaimSearchResult>, totalRecords: int}
     */
    public static function searchClaims(array $postData): array
    {
        $pageIndex = TypeCoerce::asInt($postData['pageIndex'] ?? 0);
        $model = self::buildSearchModel($postData, $pageIndex, 50);

        $raw = ClaimSearch::search($model);
        if ($raw === false) {
            return ['results' => [], 'totalRecords' => 0];
        }

        $rawResults = $raw['results'] ?? $raw;
        if (!is_array($rawResults)) {
            $rawResults = [];
        }

        $results = [];
        foreach ($rawResults as $item) {
            $results[] = ClaimSearchResult::fromApi($item);
        }

        $totalRaw = $raw['totalRecords'] ?? null;
        $totalRecords = is_int($totalRaw) ? $totalRaw : count($results);

        return ['results' => $results, 'totalRecords' => $totalRecords];
    }

    /**
     * Static entry point. Resolves the API client from globals and delegates.
     *
     * Deliberately has no catch: failures propagate to the caller, which is
     * public/claim_export_csv.php's own try/catch. Adding one here would
     * change what that endpoint sees.
     *
     * @param array<string, mixed> $postData
     * @return array<string, mixed>
     */
    public static function exportCsv(array $postData): array
    {
        return (new self(ClaimRevApi::makeFromGlobals()))->exportClaimsCsv($postData);
    }

    /**
     * Export the current claims search as CSV.
     *
     * @param array<string, mixed> $postData
     * @return array<string, mixed> Contains fileText and fileName
     * @throws ClaimRevApiException on API error
     */
    public function exportClaimsCsv(array $postData): array
    {
        $model = self::buildSearchModel($postData, 0, 0);
        return $this->api->searchClaimsCsv($model);
    }

    /**
     * Fetch a single claim by its ClaimRev objectId, scoped to the current
     * account. Used by the claim-status sync endpoint to re-fetch
     * authoritative status fields instead of trusting browser-supplied data.
     *
     * Returns the raw API entry (not a ClaimSearchResult DTO) so the caller
     * has the full field set, including PCN. Returns null when the id is
     * unknown / not visible to the current account.
     *
     * @return array<string, mixed>|null
     */
    public static function getClaimByObjectId(string $objectId): ?array
    {
        if ($objectId === '') {
            return null;
        }

        $model = new ClaimSearchModel();
        $model->objectId = $objectId;
        $model->pagingSearch->pageIndex = 0;
        $model->pagingSearch->pageSize = 1;

        $raw = ClaimSearch::search($model);
        if ($raw === false) {
            return null;
        }
        $rawResults = $raw['results'] ?? $raw;
        if (!is_array($rawResults)) {
            return null;
        }
        foreach ($rawResults as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            // Defensive: confirm the returned row matches the requested
            // objectId. The .NET filter is authoritative; the second check
            // keeps a server-side bug from leaking a different claim through.
            if (TypeCoerce::asString($entry['objectId'] ?? '') === $objectId) {
                /** @var array<string, mixed> $entry */
                return $entry;
            }
        }
        return null;
    }

    /**
     * Static entry point. Resolves the API client from globals and delegates.
     *
     * Returns an empty list on any ClaimRevException. This catch is broader
     * than the module's current convention would choose, but it is
     * long-standing behaviour that public/claims.php relies on for its status
     * dropdown, so it is preserved verbatim rather than narrowed here.
     *
     * @return list<array<string, mixed>>
     */
    public static function getClaimStatuses(): array
    {
        try {
            return (new self(ClaimRevApi::makeFromGlobals()))->fetchClaimStatuses();
        } catch (ClaimRevException) {
            return [];
        }
    }

    /**
     * Fetch the available claim statuses.
     *
     * @return list<array<string, mixed>>
     * @throws ClaimRevApiException on API error
     */
    public function fetchClaimStatuses(): array
    {
        return $this->api->getClaimStatuses();
    }

    /**
     * Build a ClaimSearchModel from POST data, applying paging and sort.
     *
     * @param array<string, mixed> $postData
     */
    private static function buildSearchModel(array $postData, int $pageIndex, int $pageSize): ClaimSearchModel
    {
        $model = new ClaimSearchModel();
        $model->patientFirstName = TypeCoerce::asString($postData['patFirstName'] ?? '');
        $model->patientLastName = TypeCoerce::asString($postData['patLastName'] ?? '');
        $model->patientGender = TypeCoerce::asString($postData['patientGender'] ?? '');
        $model->patientBirthDate = self::nonEmptyString($postData['patientBirthDate'] ?? null);
        $model->receivedDateStart = self::nonEmptyString($postData['startDate'] ?? null);
        $model->receivedDateEnd = self::nonEmptyString($postData['endDate'] ?? null);
        $model->serviceDateStart = self::nonEmptyString($postData['serviceDateStart'] ?? null);
        $model->serviceDateEnd = self::nonEmptyString($postData['serviceDateEnd'] ?? null);
        $model->payerName = TypeCoerce::asString($postData['payerName'] ?? '');
        $model->payerNumber = TypeCoerce::asString($postData['payerNumber'] ?? '');
        $model->payerPaidAmtStart = self::nonEmptyFloat($postData['payerPaidAmtStart'] ?? null);
        $model->payerPaidAmtEnd = self::nonEmptyFloat($postData['payerPaidAmtEnd'] ?? null);
        $model->traceNumber = TypeCoerce::asString($postData['traceNumber'] ?? '');
        $model->patientControlNumber = TypeCoerce::asString($postData['patientControlNumber'] ?? '');
        $model->payerControlNumber = TypeCoerce::asString($postData['payerControlNumber'] ?? '');
        $model->billingProviderNpi = TypeCoerce::asString($postData['billingProviderNpi'] ?? '');
        $model->errorMessage = TypeCoerce::asString($postData['errorMessage'] ?? '');

        $statusId = TypeCoerce::asString($postData['statusId'] ?? '');
        if ($statusId !== '') {
            $model->statusIds = [(int) $statusId];
        }

        $model->pagingSearch->pageIndex = $pageIndex;
        if ($pageSize > 0) {
            $model->pagingSearch->pageSize = $pageSize;
        }

        $sortField = TypeCoerce::asString($postData['sortField'] ?? '');
        $sortDir = TypeCoerce::asString($postData['sortDirection'] ?? '');
        if ($sortField !== '') {
            $model->sorting = [[
                'fieldName' => $sortField,
                'sortDirection' => $sortDir === 'desc' ? -1 : 1,
                'priority' => 1,
            ]];
        }

        return $model;
    }

    private static function nonEmptyString(mixed $v): ?string
    {
        if (is_string($v) && $v !== '') {
            return $v;
        }
        return null;
    }

    private static function nonEmptyFloat(mixed $v): ?float
    {
        if (is_string($v) && $v !== '' && is_numeric($v)) {
            return (float) $v;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        return null;
    }
}
