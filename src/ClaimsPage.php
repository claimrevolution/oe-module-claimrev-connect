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

namespace OpenEMR\Modules\ClaimRevConnector;

use OpenEMR\Modules\ClaimRevConnector\ClaimSearch;
use OpenEMR\Modules\ClaimRevConnector\ClaimSearchModel;

class ClaimsPage
{
    /**
     * @param array<string, mixed> $postData
     */
    public static function searchClaims(array $postData)
    {
        $pageIndex = isset($postData['pageIndex']) ? (int)$postData['pageIndex'] : 0;

        $model = new ClaimSearchModel();
        $model->patientFirstName = $postData['patFirstName'] ?? '';
        $model->patientLastName = $postData['patLastName'] ?? '';
        $model->patientGender = $postData['patientGender'] ?? '';
        $model->patientBirthDate = !empty($postData['patientBirthDate']) ? $postData['patientBirthDate'] : null;
        $model->receivedDateStart = !empty($postData['startDate']) ? $postData['startDate'] : null;
        $model->receivedDateEnd = !empty($postData['endDate']) ? $postData['endDate'] : null;
        $model->serviceDateStart = !empty($postData['serviceDateStart']) ? $postData['serviceDateStart'] : null;
        $model->serviceDateEnd = !empty($postData['serviceDateEnd']) ? $postData['serviceDateEnd'] : null;
        $model->payerName = $postData['payerName'] ?? '';
        $model->payerNumber = $postData['payerNumber'] ?? '';
        $model->payerPaidAmtStart = !empty($postData['payerPaidAmtStart']) ? (float)$postData['payerPaidAmtStart'] : null;
        $model->payerPaidAmtEnd = !empty($postData['payerPaidAmtEnd']) ? (float)$postData['payerPaidAmtEnd'] : null;
        $model->traceNumber = $postData['traceNumber'] ?? '';
        $model->patientControlNumber = $postData['patientControlNumber'] ?? '';
        $model->payerControlNumber = $postData['payerControlNumber'] ?? '';
        $model->billingProviderNpi = $postData['billingProviderNpi'] ?? '';
        $model->errorMessage = $postData['errorMessage'] ?? '';

        $statusId = $postData['statusId'] ?? '';
        if ($statusId !== '') {
            $model->statusIds = [(int)$statusId];
        }

        $model->pagingSearch->pageIndex = $pageIndex;
        $model->pagingSearch->pageSize = 50;
        $model->pagingSearch->sortField = $postData['sortField'] ?? '';
        $model->pagingSearch->sortDirection = $postData['sortDirection'] ?? '';

        $data = ClaimSearch::search($model);
        return $data;
    }

    /**
     * @param array<string, mixed> $postData
     * @return array<string, mixed>
     */
    public static function exportCsv(array $postData): array
    {
        $model = new ClaimSearchModel();
        $model->patientFirstName = $postData['patFirstName'] ?? '';
        $model->patientLastName = $postData['patLastName'] ?? '';
        $model->patientGender = $postData['patientGender'] ?? '';
        $model->patientBirthDate = !empty($postData['patientBirthDate']) ? $postData['patientBirthDate'] : null;
        $model->receivedDateStart = !empty($postData['startDate']) ? $postData['startDate'] : null;
        $model->receivedDateEnd = !empty($postData['endDate']) ? $postData['endDate'] : null;
        $model->serviceDateStart = !empty($postData['serviceDateStart']) ? $postData['serviceDateStart'] : null;
        $model->serviceDateEnd = !empty($postData['serviceDateEnd']) ? $postData['serviceDateEnd'] : null;
        $model->payerName = $postData['payerName'] ?? '';
        $model->payerNumber = $postData['payerNumber'] ?? '';
        $model->payerPaidAmtStart = !empty($postData['payerPaidAmtStart']) ? (float)$postData['payerPaidAmtStart'] : null;
        $model->payerPaidAmtEnd = !empty($postData['payerPaidAmtEnd']) ? (float)$postData['payerPaidAmtEnd'] : null;
        $model->traceNumber = $postData['traceNumber'] ?? '';
        $model->patientControlNumber = $postData['patientControlNumber'] ?? '';
        $model->payerControlNumber = $postData['payerControlNumber'] ?? '';
        $model->billingProviderNpi = $postData['billingProviderNpi'] ?? '';
        $model->errorMessage = $postData['errorMessage'] ?? '';

        $statusId = $postData['statusId'] ?? '';
        if ($statusId !== '') {
            $model->statusIds = [(int)$statusId];
        }

        $model->pagingSearch->sortField = $postData['sortField'] ?? '';
        $model->pagingSearch->sortDirection = $postData['sortDirection'] ?? '';

        $api = ClaimRevApi::makeFromGlobals();
        return $api->searchClaimsCsv($model);
    }

    public static function getClaimStatuses()
    {
        try {
            $api = ClaimRevApi::makeFromGlobals();
            return $api->getClaimStatuses();
        } catch (ClaimRevException) {
            return [];
        }
    }
}
