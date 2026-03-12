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

// $coverageResults is set by the caller (individual->coverageDiscovery)
if ($coverageResults === null || (is_array($coverageResults) && count($coverageResults) === 0)) {
    echo xlt("No coverage discovered");
    return;
}
?>
<div class="card mb-2">
    <div class="card-header"><?php echo xlt("Coverage Discovery Results"); ?></div>
    <div class="card-body">
        <?php
        $cdIndex = 0;
        foreach ($coverageResults as $coverage) {
            $cdIndex++;
            ?>
            <div class="<?php echo $cdIndex > 1 ? 'border-top pt-3 mt-3' : ''; ?>">
                <?php if (!empty($coverage->status)) {
                    $statusStyle = ($coverage->status === "Active Coverage") ? "color:green" : "color:red";
                    ?>
                    <div class="row mb-1">
                        <div class="col-3 font-weight-bold"><?php echo xlt("Coverage Status"); ?>:</div>
                        <div class="col" style="<?php echo attr($statusStyle); ?>"><?php echo text($coverage->status); ?></div>
                    </div>
                <?php } ?>
                <?php if (!empty($coverage->payerInfo)) { ?>
                    <div class="row mb-1">
                        <div class="col-3 font-weight-bold"><?php echo xlt("Payer"); ?>:</div>
                        <div class="col">
                            <?php echo text($coverage->payerInfo->payerName ?? ''); ?>
                            <?php if (!empty($coverage->payerInfo->payerCode)) { ?>
                                <small class="text-muted">(#<?php echo text($coverage->payerInfo->payerCode); ?>)</small>
                            <?php } ?>
                        </div>
                    </div>
                    <?php if (!empty($coverage->payerInfo->payerAddress1)) { ?>
                        <div class="row mb-1">
                            <div class="col-3 font-weight-bold"><?php echo xlt("Payer Address"); ?>:</div>
                            <div class="col">
                                <?php echo text($coverage->payerInfo->payerAddress1); ?>
                                <?php if (!empty($coverage->payerInfo->payerCity)) { ?>
                                    , <?php echo text($coverage->payerInfo->payerCity); ?>, <?php echo text($coverage->payerInfo->payerState ?? ''); ?> <?php echo text($coverage->payerInfo->payerZip ?? ''); ?>
                                <?php } ?>
                            </div>
                        </div>
                    <?php } ?>
                <?php } ?>
                <?php if (!empty($coverage->subscriberId)) { ?>
                    <div class="row mb-1">
                        <div class="col-3 font-weight-bold"><?php echo xlt("Subscriber ID"); ?>:</div>
                        <div class="col"><?php echo text($coverage->subscriberId); ?></div>
                    </div>
                <?php } ?>
                <?php if (!empty($coverage->groupNumber)) { ?>
                    <div class="row mb-1">
                        <div class="col-3 font-weight-bold"><?php echo xlt("Group #"); ?>:</div>
                        <div class="col"><?php echo text($coverage->groupNumber); ?></div>
                    </div>
                <?php } ?>
                <?php if (!empty($coverage->groupName)) { ?>
                    <div class="row mb-1">
                        <div class="col-3 font-weight-bold"><?php echo xlt("Group Name"); ?>:</div>
                        <div class="col"><?php echo text($coverage->groupName); ?></div>
                    </div>
                <?php } ?>
                <?php if (!empty($coverage->insuranceType)) { ?>
                    <div class="row mb-1">
                        <div class="col-3 font-weight-bold"><?php echo xlt("Insurance Type"); ?>:</div>
                        <div class="col"><?php echo text($coverage->insuranceType); ?></div>
                    </div>
                <?php } ?>
                <?php if (!empty($coverage->insurancePlan)) { ?>
                    <div class="row mb-1">
                        <div class="col-3 font-weight-bold"><?php echo xlt("Plan"); ?>:</div>
                        <div class="col"><?php echo text($coverage->insurancePlan); ?></div>
                    </div>
                <?php } ?>
                <?php if (!empty($coverage->policyDate)) { ?>
                    <?php if (!empty($coverage->policyDate->startDate)) { ?>
                        <div class="row mb-1">
                            <div class="col-3 font-weight-bold"><?php echo xlt("Policy Start"); ?>:</div>
                            <div class="col"><?php echo text(substr($coverage->policyDate->startDate, 0, 10)); ?></div>
                        </div>
                    <?php } ?>
                    <?php if (!empty($coverage->policyDate->endDate)) { ?>
                        <div class="row mb-1">
                            <div class="col-3 font-weight-bold"><?php echo xlt("Policy End"); ?>:</div>
                            <div class="col"><?php echo text(substr($coverage->policyDate->endDate, 0, 10)); ?></div>
                        </div>
                    <?php } ?>
                <?php } ?>
                <?php if (!empty($coverage->confidenceScore)) { ?>
                    <div class="row mb-1">
                        <div class="col-3 font-weight-bold"><?php echo xlt("Confidence"); ?>:</div>
                        <div class="col"><?php echo text($coverage->confidenceScore); ?>
                            <?php if (!empty($coverage->confidenceScoreReason)) { ?>
                                <small class="text-muted">(<?php echo text($coverage->confidenceScoreReason); ?>)</small>
                            <?php } ?>
                        </div>
                    </div>
                <?php } ?>
                <?php if (!empty($coverage->mbi)) { ?>
                    <div class="row mb-1">
                        <div class="col-3 font-weight-bold"><?php echo xlt("MBI"); ?>:</div>
                        <div class="col"><?php echo text($coverage->mbi); ?></div>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>
    </div>
</div>
