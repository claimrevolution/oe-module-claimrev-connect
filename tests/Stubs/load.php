<?php

/**
 * Loads every stub in dependency order. support.php must come first: the
 * class stubs read ClaimRevStubState, and ServiceContainer returns
 * ClaimRevStubLogger.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/OpenEMR/Core/OEGlobalsBag.php';
require_once __DIR__ . '/OpenEMR/BC/ServiceContainer.php';
require_once __DIR__ . '/OpenEMR/Common/Database/QueryUtils.php';
require_once __DIR__ . '/OpenEMR/Billing/BillingProcessor/X12RemoteTracker.php';
