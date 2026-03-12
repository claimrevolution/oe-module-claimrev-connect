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

// $mbiResults is set by the caller (individual->mbiFinderResults)
if ($mbiResults === null) {
    echo xlt("No MBI results");
    return;
}
?>
<div class="card mb-2">
    <div class="card-header"><?php echo xlt("MBI Finder Results"); ?></div>
    <div class="card-body">
        <?php if (!empty($mbiResults->mbiFinderStatus)) { ?>
            <div class="row mb-1">
                <div class="col-3 font-weight-bold"><?php echo xlt("Status"); ?>:</div>
                <div class="col"><?php echo text($mbiResults->mbiFinderStatus); ?></div>
            </div>
        <?php } ?>
        <?php if (!empty($mbiResults->foundMbi)) { ?>
            <div class="row mb-1">
                <div class="col-3 font-weight-bold"><?php echo xlt("MBI Number"); ?>:</div>
                <div class="col">
                    <span class="font-weight-bold text-success" style="font-size: 1.1em;"><?php echo text($mbiResults->foundMbi); ?></span>
                </div>
            </div>
        <?php } ?>
        <?php if (!empty($mbiResults->mbiFinderErrorMessage)) { ?>
            <div class="row mb-1">
                <div class="col-3 font-weight-bold text-danger"><?php echo xlt("Error"); ?>:</div>
                <div class="col text-danger"><?php echo text($mbiResults->mbiFinderErrorMessage); ?></div>
            </div>
        <?php } ?>
    </div>
</div>
