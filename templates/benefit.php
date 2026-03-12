<?php

/**
 * Benefit display with filtering — matches the ClaimRev portal's benefits table.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Brad Sharp <brad.sharp@claimrev.com>
 * @copyright Copyright (c) 2022 Brad Sharp <brad.sharp@claimrev.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

use OpenEMR\Modules\ClaimRevConnector\PrintProperty;

$benefitPatResponse = ["B","C","G","J","Y"];

// Coverage level code mapping (matches portal)
$coverageLevelMap = [
    'CHD' => 'Children Only',
    'DEP' => 'Dependents Only',
    'ECH' => 'Employee and Children',
    'EMP' => 'Employee Only',
    'ESP' => 'Employee and Spouse',
    'FAM' => 'Family',
    'IND' => 'Individual',
    'SPC' => 'Spouse and Children',
    'SPO' => 'Spouse Only',
];

// In-network indicator mapping (matches portal)
$inNetworkMap = [
    'Y' => 'Yes',
    'N' => 'No',
    'U' => 'Unknown',
    'W' => 'N/A',
];

// Build unique ID for this benefit table instance
$benefitTableId = 'benefit-table-' . ($prKey ?? 'x') . '-' . ($index ?? '0');

// Collect all unique service types for the filter dropdown
$allServiceTypes = [];
foreach ($benefits as $benefit) {
    if (property_exists($benefit, 'serviceTypes') && is_array($benefit->serviceTypes)) {
        foreach ($benefit->serviceTypes as $st) {
            $code = $st->serviceTypeCode ?? '';
            $desc = $st->serviceTypeDesc ?? $code;
            if ($code !== '' && !isset($allServiceTypes[$code])) {
                $allServiceTypes[$code] = $desc;
            }
        }
    }
}
asort($allServiceTypes);
?>

<!-- Service Type Filter -->
<?php if (!empty($allServiceTypes)) { ?>
<div class="card mb-2">
    <div class="card-body py-2">
        <div class="mb-2">
            <label class="font-weight-bold mb-1"><i class="fa fa-filter"></i> <?php echo xlt("Filter Benefits by Service Type"); ?></label>
            <div class="input-group input-group-sm mb-1">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-search"></i></span>
                </div>
                <input type="text" class="form-control" id="<?php echo attr($benefitTableId); ?>-search"
                       placeholder="<?php echo attr(xl('Search service types...')); ?>"
                       data-table="<?php echo attr($benefitTableId); ?>">
            </div>
        </div>
        <div class="form-row align-items-start">
            <div class="col">
                <select id="<?php echo attr($benefitTableId); ?>-filter" class="form-control form-control-sm benefit-service-filter" multiple
                        data-table="<?php echo attr($benefitTableId); ?>"
                        style="min-height: 100px; max-height: 160px;">
                    <?php foreach ($allServiceTypes as $stCode => $stDesc) { ?>
                        <option value="<?php echo attr($stCode); ?>"><?php echo text($stDesc); ?> (<?php echo text($stCode); ?>)</option>
                    <?php } ?>
                </select>
                <small class="text-muted"><?php echo xlt("Ctrl+click to select multiple"); ?></small>
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-sm btn-outline-primary mb-1" onclick="crBenefitSelectAll('<?php echo attr($benefitTableId); ?>')">
                    <?php echo xlt("Select All"); ?>
                </button><br>
                <button type="button" class="btn btn-sm btn-outline-secondary mb-1" onclick="crBenefitFilterClear('<?php echo attr($benefitTableId); ?>')">
                    <?php echo xlt("Clear All"); ?>
                </button>
                <div class="mt-1">
                    <span class="text-muted small" id="<?php echo attr($benefitTableId); ?>-count"></span>
                </div>
            </div>
        </div>
    </div>
</div>
<?php } ?>

<!-- Benefits Table -->
<table class="table table-sm table-hover" id="<?php echo attr($benefitTableId); ?>">
    <thead class="thead-light">
        <tr>
            <th scope="col" style="width:30px"></th>
            <th scope="col"><?php echo xlt("Benefit Type"); ?></th>
            <th scope="col"><?php echo xlt("Coverage Level"); ?></th>
            <th scope="col"><?php echo xlt("Service Type"); ?></th>
            <th scope="col"><?php echo xlt("Amount"); ?></th>
            <th scope="col"><?php echo xlt("In Network"); ?></th>
        </tr>
    </thead>
    <tbody>
    <?php
    $bIdx = 0;
    foreach ($benefits as $benefit) {
        $bIdx++;
        $rowId = $benefitTableId . '-row-' . $bIdx;
        $detailId = $benefitTableId . '-detail-' . $bIdx;

        // Collect service type codes for this benefit (for filtering)
        $stCodes = [];
        $stDescs = [];
        if (property_exists($benefit, 'serviceTypes') && is_array($benefit->serviceTypes)) {
            foreach ($benefit->serviceTypes as $st) {
                if (!empty($st->serviceTypeCode)) {
                    $stCodes[] = $st->serviceTypeCode;
                    $stDescs[] = $st->serviceTypeDesc ?? $st->serviceTypeCode;
                }
            }
        }
        $stCodesJson = attr(implode(',', $stCodes));

        // Service type summary (show first 2, then "+N more")
        $stSummary = '';
        $stTooltip = '';
        if (count($stDescs) === 0) {
            $stSummary = '—';
        } elseif (count($stDescs) <= 2) {
            $stSummary = implode(', ', $stDescs);
        } else {
            $stSummary = $stDescs[0] . ' +' . (count($stDescs) - 1) . ' more';
            $stTooltip = implode(', ', $stDescs);
        }

        // Coverage level
        $clCode = $benefit->coverageLevel ?? '';
        $clDesc = $coverageLevelMap[$clCode] ?? $clCode;

        // In-network
        $inNet = $benefit->inPlanNetworkIndicator ?? '';
        $inNetDesc = $inNetworkMap[$inNet] ?? ($inNet ?: '—');
        $inNetClass = '';
        if ($inNet === 'Y') {
            $inNetClass = 'badge badge-success';
        } elseif ($inNet === 'N') {
            $inNetClass = 'badge badge-danger';
        } elseif ($inNet !== '') {
            $inNetClass = 'badge badge-secondary';
        }

        // Amount display
        $amountDisplay = '—';
        $isPatResp = in_array($benefit->benefitInformation ?? '', $benefitPatResponse);
        if (!empty($benefit->benefitAmount)) {
            $amountDisplay = '$' . text($benefit->benefitAmount);
        } elseif (!empty($benefit->benefitPercent)) {
            $pct = floatval($benefit->benefitPercent) * 100;
            $amountDisplay = text(number_format($pct, 0)) . '%';
        }

        // Every row is expandable — detail content is always available
        $hasDetail = true;
        ?>
        <tr id="<?php echo attr($rowId); ?>" class="benefit-row" style="cursor:pointer;"
            data-service-types="<?php echo $stCodesJson; ?>"
            onclick="crBenefitToggleDetail('<?php echo attr($detailId); ?>')"
        >
            <td>
                <i class="fa fa-chevron-right benefit-expand-icon" id="<?php echo attr($detailId); ?>-icon"></i>
            </td>
            <td>
                <?php if ($isPatResp) { ?>
                    <span class="text-warning font-weight-bold" title="<?php echo attr(xl('Patient Responsibility')); ?>">
                        <?php echo text($benefit->benefitInformationDesc ?? '—'); ?>
                    </span>
                <?php } else { ?>
                    <?php echo text($benefit->benefitInformationDesc ?? '—'); ?>
                <?php } ?>
            </td>
            <td>
                <?php if ($clCode !== '') { ?>
                    <span title="<?php echo attr($clCode); ?>"><?php echo text($clDesc); ?></span>
                <?php } else { ?>
                    —
                <?php } ?>
            </td>
            <td>
                <?php if ($stTooltip !== '') { ?>
                    <span title="<?php echo attr($stTooltip); ?>"><?php echo text($stSummary); ?></span>
                <?php } else { ?>
                    <?php echo text($stSummary); ?>
                <?php } ?>
            </td>
            <td>
                <?php if ($isPatResp) { ?>
                    <span class="text-warning font-weight-bold"><?php echo $amountDisplay; ?></span>
                <?php } else { ?>
                    <?php echo $amountDisplay; ?>
                <?php } ?>
            </td>
            <td>
                <?php if ($inNetClass !== '') { ?>
                    <span class="<?php echo attr($inNetClass); ?>"><?php echo text($inNetDesc); ?></span>
                <?php } else { ?>
                    <?php echo text($inNetDesc); ?>
                <?php } ?>
            </td>
        </tr>
        <?php if ($hasDetail) { ?>
        <tr id="<?php echo attr($detailId); ?>" class="benefit-detail-row" style="display:none;">
            <td colspan="6">
                <div class="p-2 bg-light border-left border-primary" style="border-left-width:3px !important;">
                    <?php
                    // Additional details section
                    $hasAdditionalDetails = !empty($benefit->insuranceTypeCodeDesc)
                        || !empty($benefit->planCoverageDescription)
                        || !empty($benefit->timePeriodQualifierDesc)
                        || !empty($benefit->benefitQuantity)
                        || !empty($benefit->certificationIndicator);

                    if ($hasAdditionalDetails) { ?>
                        <div class="mb-2">
                            <strong><?php echo xlt("Additional Details"); ?></strong>
                            <div class="row mt-1">
                                <?php if (!empty($benefit->insuranceTypeCodeDesc)) { ?>
                                    <div class="col-md-4 mb-1">
                                        <small class="text-muted"><?php echo xlt("Insurance Type"); ?></small><br>
                                        <?php echo text($benefit->insuranceTypeCodeDesc); ?>
                                    </div>
                                <?php } ?>
                                <?php if (!empty($benefit->planCoverageDescription)) { ?>
                                    <div class="col-md-4 mb-1">
                                        <small class="text-muted"><?php echo xlt("Coverage Description"); ?></small><br>
                                        <?php echo text($benefit->planCoverageDescription); ?>
                                    </div>
                                <?php } ?>
                                <?php if (!empty($benefit->timePeriodQualifierDesc)) { ?>
                                    <div class="col-md-4 mb-1">
                                        <small class="text-muted"><?php echo xlt("Time Period"); ?></small><br>
                                        <?php echo text($benefit->timePeriodQualifierDesc); ?>
                                    </div>
                                <?php } ?>
                                <?php if (!empty($benefit->benefitQuantity)) { ?>
                                    <div class="col-md-4 mb-1">
                                        <small class="text-muted"><?php echo xlt("Quantity"); ?></small><br>
                                        <?php echo text($benefit->benefitQuantity); ?>
                                        <?php if (!empty($benefit->quantityQualifierDesc)) { ?>
                                            <?php echo text($benefit->quantityQualifierDesc); ?>
                                        <?php } ?>
                                    </div>
                                <?php } ?>
                                <?php if (!empty($benefit->certificationIndicator)) { ?>
                                    <div class="col-md-4 mb-1">
                                        <small class="text-muted"><?php echo xlt("Auth/Certification Required"); ?></small><br>
                                        <?php echo text($benefit->certificationIndicator); ?>
                                    </div>
                                <?php } ?>
                            </div>
                        </div>
                    <?php } ?>

                    <?php // Full service types list when there are more than 2
                    if (count($stDescs) > 2) { ?>
                        <div class="mb-2">
                            <strong><?php echo xlt("All Service Types"); ?></strong>
                            <div class="mt-1">
                                <?php foreach ($stDescs as $std) { ?>
                                    <span class="badge badge-light border mr-1 mb-1"><?php echo text($std); ?></span>
                                <?php } ?>
                            </div>
                        </div>
                    <?php } ?>

                    <?php
                    // Include existing detail sub-templates
                    include 'service_delivery.php';
                    include 'procedure_info.php';
                    include 'date_information.php';
                    include 'identifier_info.php';
                    include 'additional_info.php';
                    include 'related_entity.php';
                    include 'messages.php';
                    ?>
                </div>
            </td>
        </tr>
        <?php } ?>
    <?php } // end foreach benefits ?>
    </tbody>
</table>

<?php if (empty($benefits) || count((array) $benefits) === 0) { ?>
<div class="text-center py-4">
    <i class="fa fa-info-circle fa-2x text-muted mb-2" style="opacity:0.4"></i>
    <p class="text-muted"><?php echo xlt("No Benefits Found"); ?></p>
</div>
<?php } ?>

<script>
(function() {
    // Benefit detail row toggle
    if (typeof window.crBenefitToggleDetail === 'undefined') {
        window.crBenefitToggleDetail = function(detailId) {
            var detailRow = document.getElementById(detailId);
            var icon = document.getElementById(detailId + '-icon');
            if (!detailRow) return;
            if (detailRow.style.display === 'none') {
                detailRow.style.display = '';
                if (icon) { icon.classList.remove('fa-chevron-right'); icon.classList.add('fa-chevron-down'); }
            } else {
                detailRow.style.display = 'none';
                if (icon) { icon.classList.remove('fa-chevron-down'); icon.classList.add('fa-chevron-right'); }
            }
        };
    }

    // Benefit filter clear — deselect all and re-filter
    if (typeof window.crBenefitFilterClear === 'undefined') {
        window.crBenefitFilterClear = function(tableId) {
            var select = document.getElementById(tableId + '-filter');
            var search = document.getElementById(tableId + '-search');
            if (select) {
                for (var i = 0; i < select.options.length; i++) {
                    select.options[i].selected = false;
                }
                select.dispatchEvent(new Event('change'));
            }
            if (search) {
                search.value = '';
                search.dispatchEvent(new Event('input'));
            }
        };
    }

    // Select all visible (non-hidden) options
    if (typeof window.crBenefitSelectAll === 'undefined') {
        window.crBenefitSelectAll = function(tableId) {
            var select = document.getElementById(tableId + '-filter');
            if (!select) return;
            for (var i = 0; i < select.options.length; i++) {
                if (!select.options[i].hidden) {
                    select.options[i].selected = true;
                }
            }
            select.dispatchEvent(new Event('change'));
        };
    }

    // Apply benefit table filtering based on selected service type codes
    function applyBenefitFilter(select) {
        var tableId = select.dataset.table;
        var table = document.getElementById(tableId);
        if (!table) return;

        var selectedCodes = [];
        for (var i = 0; i < select.options.length; i++) {
            if (select.options[i].selected) {
                selectedCodes.push(select.options[i].value);
            }
        }

        var rows = table.querySelectorAll('.benefit-row');
        var detailRows = table.querySelectorAll('.benefit-detail-row');
        var visibleCount = 0;
        var totalCount = rows.length;

        rows.forEach(function(row) {
            var rowCodes = (row.dataset.serviceTypes || '').split(',').filter(function(c) { return c !== ''; });
            if (selectedCodes.length === 0) {
                row.style.display = '';
                visibleCount++;
            } else {
                var match = rowCodes.some(function(code) {
                    return selectedCodes.indexOf(code) !== -1;
                });
                row.style.display = match ? '' : 'none';
                if (match) visibleCount++;
            }
        });

        // Hide detail rows for hidden benefit rows
        detailRows.forEach(function(detailRow) {
            var benefitRowId = detailRow.id.replace('-detail-', '-row-');
            var benefitRow = document.getElementById(benefitRowId);
            if (benefitRow && benefitRow.style.display === 'none') {
                detailRow.style.display = 'none';
                var icon = document.getElementById(detailRow.id + '-icon');
                if (icon) { icon.classList.remove('fa-chevron-down'); icon.classList.add('fa-chevron-right'); }
            }
        });

        var countEl = document.getElementById(tableId + '-count');
        if (countEl) {
            countEl.textContent = selectedCodes.length > 0 ? (visibleCount + ' / ' + totalCount) : '';
        }
    }

    // Initialize search inputs — filters the <select> options as you type
    document.querySelectorAll('input[id$="-search"]').forEach(function(searchInput) {
        if (searchInput.dataset.searchInit) return;
        searchInput.dataset.searchInit = '1';

        searchInput.addEventListener('input', function() {
            var tableId = this.dataset.table;
            var select = document.getElementById(tableId + '-filter');
            if (!select) return;

            var term = this.value.toLowerCase();
            for (var i = 0; i < select.options.length; i++) {
                var optText = select.options[i].text.toLowerCase();
                select.options[i].hidden = (term !== '' && optText.indexOf(term) === -1);
            }
        });
    });

    // Initialize select change handlers
    document.querySelectorAll('.benefit-service-filter').forEach(function(select) {
        if (select.dataset.filterInit) return;
        select.dataset.filterInit = '1';

        select.addEventListener('change', function() {
            applyBenefitFilter(this);
        });
    });
})();
</script>
