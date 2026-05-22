<?php

/**
 * Compatibility shims for running the ClaimRev module on OpenEMR 7.x.
 *
 * OpenEMR 8.x introduced OEGlobalsBag and BC\ServiceContainer. This file
 * provides minimal stand-ins so the module works on both 7.x and 8.x without
 * any changes to the rest of the codebase.
 *
 * Must be included AFTER globals.php and BEFORE any module code that
 * references OEGlobalsBag or ServiceContainer.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Brad Sharp <brad.sharp@claimrev.com>
 * @copyright Copyright (c) 2026 Brad Sharp <brad.sharp@claimrev.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// OEGlobalsBag shim — wraps $GLOBALS with the same API the module uses
if (!class_exists(\OpenEMR\Core\OEGlobalsBag::class, false)) {
    // Also check autoloader in case it just hasn't been loaded yet
    if (!class_exists(\OpenEMR\Core\OEGlobalsBag::class)) {
        require_once __DIR__ . '/OEGlobalsBagShim.php';
        class_alias(\OpenEMR\Modules\ClaimRevConnector\Compat\OEGlobalsBagShim::class, \OpenEMR\Core\OEGlobalsBag::class);
    }
}

// CryptoInterface shim — on 7.x CryptoGen doesn't implement an interface
if (!interface_exists(\OpenEMR\Common\Crypto\CryptoInterface::class, false)) {
    if (!interface_exists(\OpenEMR\Common\Crypto\CryptoInterface::class)) {
        require_once __DIR__ . '/CryptoInterfaceShim.php';
        class_alias(\OpenEMR\Modules\ClaimRevConnector\Compat\CryptoInterfaceShim::class, \OpenEMR\Common\Crypto\CryptoInterface::class);
    }
}

// ServiceContainer shim — returns CryptoGen, SystemLogger, Clock
if (!class_exists(\OpenEMR\BC\ServiceContainer::class, false)) {
    if (!class_exists(\OpenEMR\BC\ServiceContainer::class)) {
        require_once __DIR__ . '/ServiceContainerShim.php';
        class_alias(\OpenEMR\Modules\ClaimRevConnector\Compat\ServiceContainerShim::class, \OpenEMR\BC\ServiceContainer::class);
    }
}
