<?php

/**
 * Claims search page with advanced filters, pagination, sorting, and expandable details.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Brad Sharp <brad.sharp@claimrev.com>
 * @copyright Copyright (c) 2022 Brad Sharp <brad.sharp@claimrev.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once "../../../../globals.php";

use OpenEMR\Common\Acl\AccessDeniedHelper;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Core\Header;
use OpenEMR\Modules\ClaimRevConnector\Bootstrap;
use OpenEMR\Modules\ClaimRevConnector\ClaimRevApiException;
use OpenEMR\Modules\ClaimRevConnector\ClaimsPage;

$tab = "claims";

// Ensure user has proper access
if (!AclMain::aclCheckCore('acct', 'bill')) {
    AccessDeniedHelper::denyWithTemplate("ACL check failed for acct/bill: ClaimRev Connect - Claims", xl("ClaimRev Connect - Claims"));
}

$claimStatuses = ClaimsPage::getClaimStatuses();

$bootstrap = new Bootstrap($GLOBALS['kernel']->getEventDispatcher());
$portalUrl = $bootstrap->getGlobalConfig()->getPortalUrl();
?>

<html>
    <head>
        <title><?php echo xlt("ClaimRev Connect - Claims"); ?></title>
        <?php Header::setupHeader(); ?>
        <style>
            .claim-detail-row { display: none; }
            .claim-detail-row.show { display: table-row; }
            .claim-row { cursor: pointer; }
            .claim-row:hover { background-color: rgba(0,0,0,.05); }
            .claim-row.row-rejected { background-color: #ffe5e5; }
            .claim-row.row-rejected:hover { background-color: #ffd6d6; }
            .claim-row.row-accepted { background-color: #e6ffed; }
            .claim-row.row-accepted:hover { background-color: #d6ffe0; }
            .claim-row.row-pending { background-color: #fff9e5; }
            .claim-row.row-pending:hover { background-color: #fff3cc; }
            .badge-status { font-size: 0.85em; padding: 4px 8px; }
            .claim-detail-cell { background-color: rgba(0,0,0,.02); }
            .detail-label { font-weight: bold; color: #666; font-size: 0.85em; }
            .detail-value { font-size: 0.85em; }
            .sortable-header { cursor: pointer; user-select: none; white-space: nowrap; }
            .sortable-header:hover { background-color: rgba(0,0,0,.075); }
            .status-icons { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
            .status-icon { font-size: 1.1em; }
            .status-icon.text-accepted { color: #28a745; }
            .status-icon.text-rejected { color: #dc3545; }
            .status-icon.text-pending { color: #6c757d; }
            .status-icon.text-warning { color: #ff9800; }
            .status-icon.text-processing { color: #ffc107; }
            .status-icon.text-payer-pending { color: #9c27b0; }
            .status-icon.text-era-paid { color: #28a745; }
            .status-icon.text-era-partial { color: #4caf50; }
            .status-icon.text-era-denied { color: #f44336; }
            .status-icon.text-era-pending { color: #6c757d; }
            .status-label { font-size: 0.8em; display: block; margin-top: 2px; }
        </style>
    </head>
    <body class="body_top">
        <div class="container-fluid">
            <?php require '../templates/navbar.php'; ?>
            <form method="post" action="claims.php" id="claimSearchForm">
                <input type="hidden" name="sortField" id="sortField" value="<?php echo isset($_POST['sortField']) ? attr($_POST['sortField']) : ''; ?>"/>
                <input type="hidden" name="sortDirection" id="sortDirection" value="<?php echo isset($_POST['sortDirection']) ? attr($_POST['sortDirection']) : ''; ?>"/>
                <div class="card mt-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <?php echo xlt("Search Claims"); ?>
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="clearSearchBtn" title="<?php echo xla("Clear search and saved filters"); ?>">
                                <i class="fa fa-times"></i> <?php echo xlt("Clear"); ?>
                            </button>
                            <button class="btn btn-sm btn-link" type="button" data-toggle="collapse" data-target="#moreFilters" aria-expanded="false">
                                <?php echo xlt("More Filters"); ?>
                            </button>
                        </div>
                    </div>
                    <div class="card-body pb-0">
                        <!-- Primary filters -->
                        <div class="form-row">
                            <div class="form-group col-md-2">
                                <label for="startDate"><?php echo xlt("Send Date Start"); ?></label>
                                <input type="date" class="form-control form-control-sm" id="startDate" name="startDate" value="<?php echo isset($_POST['startDate']) ? attr($_POST['startDate']) : ''; ?>"/>
                            </div>
                            <div class="form-group col-md-2">
                                <label for="endDate"><?php echo xlt("Send Date End"); ?></label>
                                <input type="date" class="form-control form-control-sm" id="endDate" name="endDate" value="<?php echo isset($_POST['endDate']) ? attr($_POST['endDate']) : ''; ?>"/>
                            </div>
                            <div class="form-group col-md-2">
                                <label for="patFirstName"><?php echo xlt("Patient First"); ?></label>
                                <input type="text" class="form-control form-control-sm" id="patFirstName" name="patFirstName" value="<?php echo isset($_POST['patFirstName']) ? attr($_POST['patFirstName']) : ''; ?>"/>
                            </div>
                            <div class="form-group col-md-2">
                                <label for="patLastName"><?php echo xlt("Patient Last"); ?></label>
                                <input type="text" class="form-control form-control-sm" id="patLastName" name="patLastName" value="<?php echo isset($_POST['patLastName']) ? attr($_POST['patLastName']) : ''; ?>"/>
                            </div>
                            <div class="form-group col-md-2">
                                <label for="statusId"><?php echo xlt("Claim Status"); ?></label>
                                <select class="form-control form-control-sm" id="statusId" name="statusId">
                                    <option value=""><?php echo xlt("All"); ?></option>
                                    <?php foreach ($claimStatuses as $status) {
                                        $statusId = $status['listItemId'] ?? '';
                                        $statusName = $status['listName'] ?? '';
                                        if ($statusName === '') {
                                            continue;
                                        }
                                        ?>
                                        <option value="<?php echo attr($statusId); ?>" <?php echo (isset($_POST['statusId']) && $_POST['statusId'] == $statusId) ? 'selected' : ''; ?>><?php echo text($statusName); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="form-group col-md-2 d-flex align-items-end">
                                <button type="submit" name="SubmitButton" class="btn btn-primary btn-sm btn-block"><?php echo xlt("Search"); ?></button>
                            </div>
                        </div>

                        <!-- Additional filters - collapsible -->
                        <div class="collapse" id="moreFilters">
                            <hr class="mt-0"/>
                            <div class="form-row">
                                <div class="form-group col-md-2">
                                    <label for="serviceDateStart"><?php echo xlt("Service Date Start"); ?></label>
                                    <input type="date" class="form-control form-control-sm" id="serviceDateStart" name="serviceDateStart" value="<?php echo isset($_POST['serviceDateStart']) ? attr($_POST['serviceDateStart']) : ''; ?>"/>
                                </div>
                                <div class="form-group col-md-2">
                                    <label for="serviceDateEnd"><?php echo xlt("Service Date End"); ?></label>
                                    <input type="date" class="form-control form-control-sm" id="serviceDateEnd" name="serviceDateEnd" value="<?php echo isset($_POST['serviceDateEnd']) ? attr($_POST['serviceDateEnd']) : ''; ?>"/>
                                </div>
                                <div class="form-group col-md-2">
                                    <label for="patientBirthDate"><?php echo xlt("Date of Birth"); ?></label>
                                    <input type="date" class="form-control form-control-sm" id="patientBirthDate" name="patientBirthDate" value="<?php echo isset($_POST['patientBirthDate']) ? attr($_POST['patientBirthDate']) : ''; ?>"/>
                                </div>
                                <div class="form-group col-md-2">
                                    <label for="patientGender"><?php echo xlt("Gender"); ?></label>
                                    <select class="form-control form-control-sm" id="patientGender" name="patientGender">
                                        <option value=""><?php echo xlt("All"); ?></option>
                                        <option value="M" <?php echo (isset($_POST['patientGender']) && $_POST['patientGender'] === 'M') ? 'selected' : ''; ?>><?php echo xlt("Male"); ?></option>
                                        <option value="F" <?php echo (isset($_POST['patientGender']) && $_POST['patientGender'] === 'F') ? 'selected' : ''; ?>><?php echo xlt("Female"); ?></option>
                                    </select>
                                </div>
                                <div class="form-group col-md-2">
                                    <label for="payerName"><?php echo xlt("Payer Name"); ?></label>
                                    <input type="text" class="form-control form-control-sm" id="payerName" name="payerName" value="<?php echo isset($_POST['payerName']) ? attr($_POST['payerName']) : ''; ?>"/>
                                </div>
                                <div class="form-group col-md-2">
                                    <label for="payerNumber"><?php echo xlt("Payer Number"); ?></label>
                                    <input type="text" class="form-control form-control-sm" id="payerNumber" name="payerNumber" value="<?php echo isset($_POST['payerNumber']) ? attr($_POST['payerNumber']) : ''; ?>"/>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group col-md-2">
                                    <label for="billingProviderNpi"><?php echo xlt("Billing NPI"); ?></label>
                                    <input type="text" class="form-control form-control-sm" id="billingProviderNpi" name="billingProviderNpi" value="<?php echo isset($_POST['billingProviderNpi']) ? attr($_POST['billingProviderNpi']) : ''; ?>"/>
                                </div>
                                <div class="form-group col-md-2">
                                    <label for="traceNumber"><?php echo xlt("Trace Number"); ?></label>
                                    <input type="text" class="form-control form-control-sm" id="traceNumber" name="traceNumber" value="<?php echo isset($_POST['traceNumber']) ? attr($_POST['traceNumber']) : ''; ?>"/>
                                </div>
                                <div class="form-group col-md-2">
                                    <label for="patientControlNumber"><?php echo xlt("Patient Control #"); ?></label>
                                    <input type="text" class="form-control form-control-sm" id="patientControlNumber" name="patientControlNumber" value="<?php echo isset($_POST['patientControlNumber']) ? attr($_POST['patientControlNumber']) : ''; ?>"/>
                                </div>
                                <div class="form-group col-md-2">
                                    <label for="payerControlNumber"><?php echo xlt("Payer Control #"); ?></label>
                                    <input type="text" class="form-control form-control-sm" id="payerControlNumber" name="payerControlNumber" value="<?php echo isset($_POST['payerControlNumber']) ? attr($_POST['payerControlNumber']) : ''; ?>"/>
                                </div>
                                <div class="form-group col-md-1">
                                    <label for="payerPaidAmtStart"><?php echo xlt("Paid Min"); ?></label>
                                    <input type="number" step="0.01" class="form-control form-control-sm" id="payerPaidAmtStart" name="payerPaidAmtStart" value="<?php echo isset($_POST['payerPaidAmtStart']) ? attr($_POST['payerPaidAmtStart']) : ''; ?>"/>
                                </div>
                                <div class="form-group col-md-1">
                                    <label for="payerPaidAmtEnd"><?php echo xlt("Paid Max"); ?></label>
                                    <input type="number" step="0.01" class="form-control form-control-sm" id="payerPaidAmtEnd" name="payerPaidAmtEnd" value="<?php echo isset($_POST['payerPaidAmtEnd']) ? attr($_POST['payerPaidAmtEnd']) : ''; ?>"/>
                                </div>
                                <div class="form-group col-md-2">
                                    <label for="errorMessage"><?php echo xlt("Error Message"); ?></label>
                                    <input type="text" class="form-control form-control-sm" id="errorMessage" name="errorMessage" value="<?php echo isset($_POST['errorMessage']) ? attr($_POST['errorMessage']) : ''; ?>"/>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

        <?php
            $datas = [];
            $totalRecords = 0;
            $pageIndex = isset($_POST['pageIndex']) ? (int)$_POST['pageIndex'] : 0;
            $pageSize = 50;
        if (isset($_POST['SubmitButton']) || isset($_POST['pageIndex'])) {
            try {
                $pagedResult = ClaimsPage::searchClaims($_POST);
                if ($pagedResult !== false && $pagedResult !== null) {
                    if (isset($pagedResult['results'])) {
                        // Convert array results to objects for template compatibility
                        $datas = json_decode(json_encode($pagedResult['results']));
                        $totalRecords = $pagedResult['totalRecords'] ?? 0;
                    } elseif (is_array($pagedResult)) {
                        $datas = json_decode(json_encode($pagedResult));
                        $totalRecords = count($datas);
                    }
                }
            } catch (\Throwable $t) {
                echo "<div class='alert alert-danger mt-3'>" . text($t->getMessage()) . "</div>";
            }
        }
        if (empty($datas)) {
            if (isset($_POST['SubmitButton']) || isset($_POST['pageIndex'])) {
                echo "<div class='alert alert-info mt-3'>" . xlt("No results found") . "</div>";
            }
        } else {
            $totalPages = ceil($totalRecords / $pageSize);
            $currentSort = $_POST['sortField'] ?? '';
            $currentDir = $_POST['sortDirection'] ?? '';
            // Helper to render sort indicator
            function sortIcon($field, $currentSort, $currentDir)
            {
                if ($currentSort !== $field) {
                    return ' <i class="fa fa-sort text-muted"></i>';
                }
                return $currentDir === 'desc'
                    ? ' <i class="fa fa-sort-down"></i>'
                    : ' <i class="fa fa-sort-up"></i>';
            }
            ?>
                <div class="mt-3 mb-2 d-flex justify-content-between align-items-center">
                    <span><?php echo text($totalRecords) . " " . xlt("total results"); ?></span>
                    <div>
                        <span class="text-muted small mr-3"><?php echo xlt("Click a row to expand details"); ?></span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="exportCsvBtn">
                            <i class="fa fa-download"></i> <?php echo xlt("Export CSV"); ?>
                        </button>
                    </div>
                    <span><?php echo xlt("Page") . " " . text($pageIndex + 1) . " " . xlt("of") . " " . text($totalPages); ?></span>
                </div>
                <table class="table table-sm table-bordered" id="claimsTable">
                <thead class="thead-light">
                    <tr>
                        <th scope="col" class="sortable-header" data-sort="statusName"><?php echo xlt("Status"); ?><?php echo sortIcon('statusName', $currentSort, $currentDir); ?></th>
                        <th scope="col" class="sortable-header" data-sort="pLastName"><?php echo xlt("Patient"); ?><?php echo sortIcon('pLastName', $currentSort, $currentDir); ?></th>
                        <th scope="col" class="sortable-header" data-sort="payerName"><?php echo xlt("Payer"); ?><?php echo sortIcon('payerName', $currentSort, $currentDir); ?></th>
                        <th scope="col" class="sortable-header" data-sort="providerLastName"><?php echo xlt("Provider"); ?><?php echo sortIcon('providerLastName', $currentSort, $currentDir); ?></th>
                        <th scope="col" class="sortable-header" data-sort="serviceDate"><?php echo xlt("Service Date"); ?><?php echo sortIcon('serviceDate', $currentSort, $currentDir); ?></th>
                        <th scope="col" class="sortable-header" data-sort="receivedDate"><?php echo xlt("Received"); ?><?php echo sortIcon('receivedDate', $currentSort, $currentDir); ?></th>
                        <th scope="col" class="sortable-header text-right" data-sort="billedAmount"><?php echo xlt("Billed"); ?><?php echo sortIcon('billedAmount', $currentSort, $currentDir); ?></th>
                        <th scope="col" class="sortable-header text-right" data-sort="payerPaidAmount"><?php echo xlt("Paid"); ?><?php echo sortIcon('payerPaidAmount', $currentSort, $currentDir); ?></th>
                        <th scope="col" class="text-center"><?php echo xlt("Actions"); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rowIndex = 0;
                    foreach ($datas as $data) {
                        $statusName = $data->statusName ?? '';
                        $statusId = (int)($data->statusId ?? 0);
                        $payerFileStatusId = (int)($data->payerFileStatusId ?? 0);
                        $payerFileStatusName = $data->payerFileStatusName ?? '';
                        $payerAcceptanceStatusId = (int)($data->payerAcceptanceStatusId ?? 0);
                        $payerAcceptance = $data->payerAcceptanceStatusName ?? '';
                        $paymentAdviceStatusId = (int)($data->paymentAdviceStatusId ?? 0);
                        $paymentAdvice = $data->paymentAdviceStatusName ?? '';
                        $eraClassification = $data->eraClassification ?? '';

                        // Claim status icon (received/processing)
                        if ($statusId === 10) {
                            $claimIcon = 'fa-times-circle';
                            $claimIconClass = 'text-rejected';
                        } elseif (in_array($statusId, [16, 17])) {
                            $claimIcon = 'fa-ban';
                            $claimIconClass = 'text-rejected';
                        } elseif (in_array($statusId, [7, 8, 9, 18])) {
                            $claimIcon = 'fa-paper-plane';
                            $claimIconClass = 'text-accepted';
                        } else {
                            $claimIcon = 'fa-cogs';
                            $claimIconClass = 'text-processing';
                        }

                        // File status icon
                        if ($payerFileStatusId === 3) {
                            $fileIcon = 'fa-times-circle';
                            $fileIconClass = 'text-rejected';
                        } elseif ($payerFileStatusId === 2) {
                            $fileIcon = 'fa-check-circle';
                            $fileIconClass = 'text-accepted';
                        } elseif ($payerFileStatusId === 1) {
                            $fileIcon = 'fa-hourglass-half';
                            $fileIconClass = 'text-pending';
                        } else {
                            $fileIcon = 'fa-clock';
                            $fileIconClass = 'text-pending';
                        }

                        // Payer acceptance icon
                        if ($payerAcceptanceStatusId === 3) {
                            $payerIcon = 'fa-times-circle';
                            $payerIconClass = 'text-rejected';
                        } elseif ($payerAcceptanceStatusId === 4) {
                            $payerIcon = 'fa-thumbs-up';
                            $payerIconClass = 'text-accepted';
                        } elseif ($payerAcceptanceStatusId === 5) {
                            $payerIcon = 'fa-clock';
                            $payerIconClass = 'text-payer-pending';
                        } elseif ($payerAcceptanceStatusId === 6) {
                            $payerIcon = 'fa-question-circle';
                            $payerIconClass = 'text-warning';
                        } elseif (in_array($payerAcceptanceStatusId, [1, 2])) {
                            $payerIcon = 'fa-hourglass-half';
                            $payerIconClass = 'text-pending';
                        } else {
                            $payerIcon = 'fa-clock';
                            $payerIconClass = 'text-pending';
                        }

                        // ERA icon
                        $eraIcon = '';
                        $eraIconClass = '';
                        if ($paymentAdviceStatusId > 0 || !empty($eraClassification)) {
                            if (stripos($eraClassification, 'denied') !== false) {
                                $eraIcon = 'fa-times-circle';
                                $eraIconClass = 'text-era-denied';
                            } elseif (stripos($eraClassification, 'partial') !== false) {
                                $eraIcon = 'fa-adjust';
                                $eraIconClass = 'text-era-partial';
                            } elseif (stripos($eraClassification, 'paid') !== false || stripos($eraClassification, 'contractual') !== false) {
                                $eraIcon = 'fa-dollar-sign';
                                $eraIconClass = 'text-era-paid';
                            } elseif (stripos($eraClassification, 'pending') !== false) {
                                $eraIcon = 'fa-clock';
                                $eraIconClass = 'text-era-pending';
                            } elseif ($paymentAdviceStatusId > 0) {
                                $eraIcon = 'fa-file-invoice-dollar';
                                $eraIconClass = 'text-pending';
                            }
                        }

                        // Row class based on portal logic
                        $rowClass = '';
                        if ($statusId === 10 || $payerAcceptanceStatusId === 3) {
                            $rowClass = 'row-rejected';
                        } elseif ($payerAcceptanceStatusId === 4) {
                            $rowClass = 'row-accepted';
                        } elseif ($statusId === 1) {
                            $rowClass = 'row-pending';
                        }

                        $isWorked = isset($data->isWorked) && $data->isWorked;
                        $objectId = $data->objectId ?? '';
                        $claimTypeId = $data->claimTypeId ?? 1;
                        $editorRoute = '';
                        if (!empty($objectId)) {
                            switch ($claimTypeId) {
                                case 2:
                                    $editorRoute = '/claimeditor/institutionaleditor/';
                                    break;
                                case 3:
                                    $editorRoute = '/claimeditor/dentaleditor/';
                                    break;
                                default:
                                    $editorRoute = '/claimeditor/professionaleditor/';
                                    break;
                            }
                        }
                        $errorCount = $data->errorCount ?? 0;
                        ?>
                        <tr class="claim-row <?php echo attr($rowClass); ?>" data-target="#detail-<?php echo attr($rowIndex); ?>">
                            <td>
                                <div class="status-icons">
                                    <span class="status-icon <?php echo attr($claimIconClass); ?>" title="<?php echo xla("Claim"); ?>: <?php echo attr($statusName); ?>">
                                        <i class="fa <?php echo attr($claimIcon); ?>"></i>
                                    </span>
                                    <span class="status-icon <?php echo attr($fileIconClass); ?>" title="<?php echo xla("File"); ?>: <?php echo attr($payerFileStatusName); ?>">
                                        <i class="fa <?php echo attr($fileIcon); ?>"></i>
                                    </span>
                                    <span class="status-icon <?php echo attr($payerIconClass); ?>" title="<?php echo xla("Payer"); ?>: <?php echo attr($payerAcceptance); ?>">
                                        <i class="fa <?php echo attr($payerIcon); ?>"></i>
                                    </span>
                                    <?php if (!empty($eraIcon)) { ?>
                                        <span class="status-icon <?php echo attr($eraIconClass); ?>" title="<?php echo xla("ERA"); ?>: <?php echo attr(!empty($eraClassification) ? $eraClassification : $paymentAdvice); ?>">
                                            <i class="fa <?php echo attr($eraIcon); ?>"></i>
                                        </span>
                                    <?php } ?>
                                    <?php if ($errorCount > 0) { ?>
                                        <span class="status-icon text-rejected" title="<?php echo attr($errorCount); ?> <?php echo xla("errors"); ?>">
                                            <i class="fa fa-exclamation-triangle"></i>
                                        </span>
                                    <?php } ?>
                                </div>
                                <span class="status-label text-muted"><?php echo text($statusName); ?></span>
                            </td>
                            <td>
                                <?php echo text($data->pLastName ?? ''); ?>, <?php echo text($data->pFirstName ?? ''); ?>
                                <br/><small class="text-muted"><?php echo xlt("DOB"); ?>: <?php echo text(substr($data->birthDate ?? '', 0, 10)); ?></small>
                            </td>
                            <td>
                                <?php echo text($data->payerName ?? ''); ?>
                                <?php if (!empty($data->payerNumber)) { ?>
                                    <br/><small class="text-muted">#<?php echo text($data->payerNumber); ?></small>
                                <?php } ?>
                            </td>
                            <td>
                                <?php echo text($data->providerLastName ?? ''); ?>, <?php echo text($data->providerFirstName ?? ''); ?>
                                <?php if (!empty($data->providerNpi)) { ?>
                                    <br/><small class="text-muted"><?php echo xlt("NPI"); ?>: <?php echo text($data->providerNpi); ?></small>
                                <?php } ?>
                            </td>
                            <td>
                                <?php echo text(substr($data->serviceDate ?? '', 0, 10)); ?>
                                <?php if (!empty($data->serviceDateEnd)) { ?>
                                    <br/><small class="text-muted"><?php echo xlt("to"); ?> <?php echo text(substr($data->serviceDateEnd, 0, 10)); ?></small>
                                <?php } ?>
                            </td>
                            <td><?php echo text(substr($data->receivedDate ?? '', 0, 10)); ?></td>
                            <td class="text-right"><?php echo text(number_format((float)($data->billedAmount ?? 0), 2)); ?></td>
                            <td class="text-right"><?php echo text(number_format((float)($data->payerPaidAmount ?? 0), 2)); ?></td>
                            <td class="text-center">
                                <?php if (!empty($objectId) && !empty($editorRoute)) { ?>
                                    <a href="<?php echo attr($portalUrl . $editorRoute . $objectId); ?>" target="_blank" class="btn btn-outline-primary btn-sm" title="<?php echo xla("Edit in Portal"); ?>" onclick="event.stopPropagation();">
                                        <i class="fa fa-external-link-alt"></i>
                                    </a>
                                <?php } ?>
                                <button type="button" class="btn btn-sm ml-1 worked-toggle <?php echo $isWorked ? 'btn-success' : 'btn-outline-secondary'; ?>"
                                    data-objectid="<?php echo attr($objectId); ?>"
                                    data-worked="<?php echo $isWorked ? '1' : '0'; ?>"
                                    title="<?php echo $isWorked ? xla("Worked - click to unmark") : xla("Not worked - click to mark"); ?>"
                                    onclick="event.stopPropagation(); toggleWorked(this);">
                                    <i class="fa fa-check"></i>
                                </button>
                            </td>
                        </tr>
                        <tr class="claim-detail-row" id="detail-<?php echo attr($rowIndex); ?>">
                            <td colspan="9" class="claim-detail-cell p-3">
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="detail-label"><?php echo xlt("ClaimRev Status"); ?></div>
                                        <div class="detail-value"><?php echo text($statusName); ?></div>
                                        <div class="detail-label mt-2"><?php echo xlt("File Status"); ?></div>
                                        <div class="detail-value"><?php echo text($data->payerFileStatusName ?? ''); ?></div>
                                        <div class="detail-label mt-2"><?php echo xlt("Payer Acceptance"); ?></div>
                                        <div class="detail-value"><?php echo text($payerAcceptance); ?></div>
                                        <div class="detail-label mt-2"><?php echo xlt("ERA Status"); ?></div>
                                        <div class="detail-value"><?php echo text($paymentAdvice); ?></div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="detail-label"><?php echo xlt("Member #"); ?></div>
                                        <div class="detail-value"><?php echo text($data->memberNumber ?? ''); ?></div>
                                        <div class="detail-label mt-2"><?php echo xlt("Trace #"); ?></div>
                                        <div class="detail-value"><?php echo text($data->traceNumber ?? ''); ?></div>
                                        <div class="detail-label mt-2"><?php echo xlt("Patient Control #"); ?></div>
                                        <div class="detail-value"><?php echo text($data->patientControlNumber ?? ''); ?></div>
                                        <div class="detail-label mt-2"><?php echo xlt("Payer Control #"); ?></div>
                                        <div class="detail-value"><?php echo text($data->payerControlNumber ?? ''); ?></div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="detail-label"><?php echo xlt("Received Date"); ?></div>
                                        <div class="detail-value"><?php echo text(substr($data->receivedDate ?? '', 0, 10)); ?></div>
                                        <div class="detail-label mt-2"><?php echo xlt("Claim Type"); ?></div>
                                        <div class="detail-value"><?php echo text($data->claimType ?? ''); ?></div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="detail-label"><?php echo xlt("Worked"); ?></div>
                                        <div class="detail-value worked-detail-<?php echo attr($objectId); ?>">
                                            <?php if ($isWorked) { ?>
                                                <span class="text-success"><i class="fa fa-check-circle"></i> <?php echo xlt("Yes"); ?></span>
                                            <?php } else { ?>
                                                <span class="text-muted"><i class="fa fa-circle"></i> <?php echo xlt("No"); ?></span>
                                            <?php } ?>
                                        </div>
                                        <?php if (!empty($objectId) && !empty($editorRoute)) { ?>
                                            <div class="mt-3">
                                                <a href="<?php echo attr($portalUrl . $editorRoute . $objectId); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                    <i class="fa fa-external-link-alt"></i> <?php echo xlt("Edit in Portal"); ?>
                                                </a>
                                            </div>
                                        <?php } ?>
                                    </div>
                                </div>
                                <?php if ($errorCount > 0) { ?>
                                    <div class="mt-3 claim-errors-section" data-claimid="<?php echo attr($objectId); ?>" data-loaded="0">
                                        <div class="detail-label"><?php echo xlt("Errors"); ?> (<?php echo text($errorCount); ?>)</div>
                                        <div class="claim-errors-content">
                                            <span class="text-muted small"><i class="fa fa-spinner fa-spin"></i> <?php echo xlt("Loading errors..."); ?></span>
                                        </div>
                                    </div>
                                <?php } ?>
                            </td>
                        </tr>
                        <?php
                        $rowIndex++;
                    } ?>
                  </tbody>
                </table>
            <?php if ($totalPages > 1) { ?>
                <nav>
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?php echo ($pageIndex <= 0) ? 'disabled' : ''; ?>">
                            <button type="submit" name="pageIndex" value="<?php echo attr($pageIndex - 1); ?>" form="claimSearchForm" class="page-link"><?php echo xlt("Previous"); ?></button>
                        </li>
                        <?php
                        $startPage = max(0, $pageIndex - 2);
                        $endPage = min($totalPages - 1, $pageIndex + 2);
                        for ($i = $startPage; $i <= $endPage; $i++) { ?>
                            <li class="page-item <?php echo ($i == $pageIndex) ? 'active' : ''; ?>">
                                <button type="submit" name="pageIndex" value="<?php echo attr($i); ?>" form="claimSearchForm" class="page-link"><?php echo text($i + 1); ?></button>
                            </li>
                        <?php } ?>
                        <li class="page-item <?php echo ($pageIndex >= $totalPages - 1) ? 'disabled' : ''; ?>">
                            <button type="submit" name="pageIndex" value="<?php echo attr($pageIndex + 1); ?>" form="claimSearchForm" class="page-link"><?php echo xlt("Next"); ?></button>
                        </li>
                    </ul>
                </nav>
            <?php } ?>
        <?php }
        ?>
        </div>
        <script>
            function toggleWorked(btn) {
                var $btn = $(btn);
                var objectId = $btn.data('objectid');
                var currentlyWorked = $btn.data('worked') === 1 || $btn.data('worked') === '1';
                var newWorked = !currentlyWorked;
                $btn.prop('disabled', true);
                $.post('claim_mark_worked.php', {
                    objectId: objectId,
                    isWorked: newWorked ? '1' : '0'
                }, function(response) {
                    if (response.success) {
                        $btn.data('worked', newWorked ? '1' : '0');
                        if (newWorked) {
                            $btn.removeClass('btn-outline-secondary').addClass('btn-success');
                        } else {
                            $btn.removeClass('btn-success').addClass('btn-outline-secondary');
                        }
                        $('.worked-toggle[data-objectid="' + objectId + '"]').each(function() {
                            var $b = $(this);
                            $b.data('worked', newWorked ? '1' : '0');
                            if (newWorked) {
                                $b.removeClass('btn-outline-secondary').addClass('btn-success');
                            } else {
                                $b.removeClass('btn-success').addClass('btn-outline-secondary');
                            }
                        });
                        var $detail = $('.worked-detail-' + objectId);
                        if ($detail.length) {
                            if (newWorked) {
                                $detail.html('<span class="text-success"><i class="fa fa-check-circle"></i> ' + <?php echo xlj("Yes"); ?> + '</span>');
                            } else {
                                $detail.html('<span class="text-muted"><i class="fa fa-circle"></i> ' + <?php echo xlj("No"); ?> + '</span>');
                            }
                        }
                    }
                }, 'json').always(function() {
                    $btn.prop('disabled', false);
                });
            }

            $(document).ready(function() {
                $('.claim-row').on('click', function() {
                    var target = $(this).data('target');
                    var $detail = $(target);
                    $detail.toggleClass('show');

                    if ($detail.hasClass('show')) {
                        $detail.find('.claim-errors-section').each(function() {
                            var $section = $(this);
                            if ($section.data('loaded') === 1) {
                                return;
                            }
                            $section.data('loaded', 1);
                            var claimId = $section.data('claimid');
                            $.get('claim_errors.php', { claimId: claimId }, function(response) {
                                var $content = $section.find('.claim-errors-content');
                                if (response.success && response.errors && response.errors.length > 0) {
                                    var html = '<ul class="mb-0 small">';
                                    response.errors.forEach(function(err) {
                                        html += '<li class="text-danger">';
                                        html += $('<span>').text(err.errorMessage || '').html();
                                        if (err.segment) {
                                            html += ' <span class="text-muted">(' + $('<span>').text('Segment: ' + err.segment).html();
                                            if (err.loopId) {
                                                html += ', Loop: ' + $('<span>').text(err.loopId).html();
                                            }
                                            html += ')</span>';
                                        }
                                        html += '</li>';
                                    });
                                    html += '</ul>';
                                    $content.html(html);
                                } else if (response.success) {
                                    $content.html('<span class="text-muted small">' + <?php echo xlj("No errors found"); ?> + '</span>');
                                } else {
                                    $content.html('<span class="text-danger small">' + <?php echo xlj("Failed to load errors"); ?> + '</span>');
                                }
                            }, 'json').fail(function() {
                                $section.find('.claim-errors-content').html('<span class="text-danger small">' + <?php echo xlj("Failed to load errors"); ?> + '</span>');
                                $section.data('loaded', 0);
                            });
                        });
                    }
                });

                $('.sortable-header').on('click', function() {
                    var field = $(this).data('sort');
                    var currentField = $('#sortField').val();
                    var currentDir = $('#sortDirection').val();
                    if (currentField === field) {
                        $('#sortDirection').val(currentDir === 'asc' ? 'desc' : 'asc');
                    } else {
                        $('#sortField').val(field);
                        $('#sortDirection').val('asc');
                    }
                    $('<input>').attr({ type: 'hidden', name: 'pageIndex', value: '0' }).appendTo('#claimSearchForm');
                    $('#claimSearchForm').submit();
                });

                $('#clearSearchBtn').on('click', function() {
                    localStorage.removeItem('claimrev_claim_search');
                    window.location.href = 'claims.php';
                });

                $('#exportCsvBtn').on('click', function() {
                    var $btn = $(this);
                    $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> ' + <?php echo xlj("Exporting..."); ?>);
                    var formData = $('#claimSearchForm').serialize();
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', 'claim_export_csv.php', true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.responseType = 'blob';
                    xhr.onload = function() {
                        if (xhr.status === 200) {
                            var disposition = xhr.getResponseHeader('Content-Disposition');
                            var fileName = 'claims_export.csv';
                            if (disposition && disposition.indexOf('filename=') !== -1) {
                                fileName = disposition.split('filename=')[1].replace(/"/g, '');
                            }
                            var blob = xhr.response;
                            var link = document.createElement('a');
                            link.href = URL.createObjectURL(blob);
                            link.download = fileName;
                            link.click();
                        } else {
                            alert(<?php echo xlj("Failed to export CSV"); ?>);
                        }
                        $btn.prop('disabled', false).html('<i class="fa fa-download"></i> ' + <?php echo xlj("Export CSV"); ?>);
                    };
                    xhr.onerror = function() {
                        alert(<?php echo xlj("Failed to export CSV"); ?>);
                        $btn.prop('disabled', false).html('<i class="fa fa-download"></i> ' + <?php echo xlj("Export CSV"); ?>);
                    };
                    xhr.send(formData);
                });
            });
        </script>
    </body>
</html>
