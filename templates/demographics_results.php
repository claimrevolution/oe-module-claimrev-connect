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

// $demographicInfo is set by the caller (individual->demographicInfo)
if ($demographicInfo === null) {
    echo xlt("No demographic results");
    return;
}
?>
<div class="card mb-2">
    <div class="card-header"><?php echo xlt("Demographics Results"); ?></div>
    <div class="card-body">
        <?php if (!empty($demographicInfo->status)) { ?>
            <div class="row mb-1">
                <div class="col-3 font-weight-bold"><?php echo xlt("Status"); ?>:</div>
                <div class="col"><?php echo text($demographicInfo->status); ?></div>
            </div>
        <?php } ?>
        <?php if (!empty($demographicInfo->confidenceScore)) { ?>
            <div class="row mb-1">
                <div class="col-3 font-weight-bold"><?php echo xlt("Confidence Score"); ?>:</div>
                <div class="col"><?php echo text($demographicInfo->confidenceScore); ?></div>
            </div>
        <?php } ?>

        <?php if (!empty($demographicInfo->correctedPerson)) {
            $person = $demographicInfo->correctedPerson; ?>
            <h6 class="mt-3"><?php echo xlt("Verified Information"); ?></h6>
            <div class="row mb-1">
                <div class="col-3 font-weight-bold"><?php echo xlt("Name"); ?>:</div>
                <div class="col">
                    <?php echo text($person->firstName ?? ''); ?> <?php echo text($person->middleName ?? ''); ?> <?php echo text($person->lastName ?? ''); ?> <?php echo text($person->suffix ?? ''); ?>
                </div>
            </div>
            <?php if (!empty($person->gender)) { ?>
                <div class="row mb-1">
                    <div class="col-3 font-weight-bold"><?php echo xlt("Gender"); ?>:</div>
                    <div class="col"><?php echo text($person->gender); ?></div>
                </div>
            <?php } ?>
            <?php if (!empty($person->dob)) { ?>
                <div class="row mb-1">
                    <div class="col-3 font-weight-bold"><?php echo xlt("DOB"); ?>:</div>
                    <div class="col"><?php echo text(substr($person->dob, 0, 10)); ?></div>
                </div>
            <?php } ?>
            <?php if (!empty($person->ssn)) { ?>
                <div class="row mb-1">
                    <div class="col-3 font-weight-bold"><?php echo xlt("SSN"); ?>:</div>
                    <div class="col"><?php echo text($person->ssn); ?></div>
                </div>
            <?php } ?>
            <?php if (!empty($person->address1)) { ?>
                <div class="row mb-1">
                    <div class="col-3 font-weight-bold"><?php echo xlt("Address"); ?>:</div>
                    <div class="col">
                        <?php echo text($person->address1); ?>
                        <?php if (!empty($person->address2)) {
                            echo ", " . text($person->address2);
                        } ?>
                        <br/><?php echo text($person->city ?? ''); ?>, <?php echo text($person->state ?? ''); ?> <?php echo text($person->zip ?? ''); ?>
                    </div>
                </div>
            <?php } ?>
            <?php if (!empty($person->phoneNumber)) { ?>
                <div class="row mb-1">
                    <div class="col-3 font-weight-bold"><?php echo xlt("Phone"); ?> (<?php echo text($person->phoneNumberType ?? ''); ?>):</div>
                    <div class="col"><?php echo text($person->phoneNumber); ?></div>
                </div>
            <?php } ?>
            <?php if (!empty($person->deceased) && $person->deceased) { ?>
                <div class="row mb-1">
                    <div class="col-3 font-weight-bold text-danger"><?php echo xlt("Deceased"); ?>:</div>
                    <div class="col text-danger"><?php echo xlt("Yes"); ?></div>
                </div>
            <?php } ?>
        <?php } ?>

        <?php if (!empty($demographicInfo->additionalAddresses)) { ?>
            <h6 class="mt-3"><?php echo xlt("Additional Addresses"); ?></h6>
            <?php foreach ($demographicInfo->additionalAddresses as $addr) { ?>
                <div class="row mb-1">
                    <div class="col">
                        <?php echo text($addr->address1 ?? ''); ?>
                        <?php if (!empty($addr->address2)) {
                            echo ", " . text($addr->address2);
                        } ?>
                        , <?php echo text($addr->city ?? ''); ?>, <?php echo text($addr->state ?? ''); ?> <?php echo text($addr->zip ?? ''); ?>
                        <?php if (!empty($addr->addressDateReported)) { ?>
                            <small class="text-muted">(<?php echo text(substr($addr->addressDateReported, 0, 10)); ?>)</small>
                        <?php } ?>
                    </div>
                </div>
            <?php } ?>
        <?php } ?>

        <?php if (!empty($demographicInfo->warnings)) { ?>
            <h6 class="mt-3 text-warning"><?php echo xlt("Warnings"); ?></h6>
            <ul class="mb-0">
            <?php foreach ($demographicInfo->warnings as $warning) { ?>
                <li class="text-warning"><?php echo text($warning); ?></li>
            <?php } ?>
            </ul>
        <?php } ?>

        <?php if (!empty($demographicInfo->redFlags)) { ?>
            <h6 class="mt-3 text-danger"><?php echo xlt("Red Flags"); ?></h6>
            <ul class="mb-0">
            <?php foreach ($demographicInfo->redFlags as $flag) { ?>
                <li class="text-danger"><?php echo text($flag); ?></li>
            <?php } ?>
            </ul>
        <?php } ?>
    </div>
</div>
