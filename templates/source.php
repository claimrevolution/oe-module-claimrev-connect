<?php

/**
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Brad Sharp <brad.sharp@claimrev.com>
 * @copyright Copyright (c) 2022 Brad Sharp <brad.sharp@claimrev.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

/** @var \stdClass|string|null $source */

declare(strict_types=1);

if ($source === null || $source === '') {
    return;
}

// $source can arrive as either a stdClass (real API: informationSource object
// with lastOrganizationName/identifier) or a plain string (mock + many real
// responses: just the payer's organization name in informationSourceName).
$payerName = '';
$payerId = '';
if (is_object($source)) {
    $payerName = property_exists($source, 'lastOrganizationName') && is_string($source->lastOrganizationName)
        ? $source->lastOrganizationName : '';
    $payerId = property_exists($source, 'identifier') && is_string($source->identifier)
        ? $source->identifier : '';
} elseif (is_string($source)) {
    $payerName = $source;
}
?>
        <div class="card">
            <div class="card-body">
                <h5 class="card-title"> <?php echo xlt("Payer Information"); ?></h5>
                <div class="row">
                    <div class="col">
                    <?php echo xlt("Payer Name"); ?>
                    </div>
                    <div class="col">
                    <?php echo text($payerName); ?>
                    </div>
                    <div class="col">
                    <?php echo xlt("Payer ID"); ?>
                    </div>
                    <div class="col">
                    <?php echo text($payerId); ?>
                    </div>
                </div>
            </div>
        </div>
