<?php

/**
 * PHPUnit bootstrap.
 *
 * Loads the Composer autoloader, then hand-written stubs for the slice of
 * OpenEMR core the module touches. OpenEMR is not installable as a dev
 * dependency, so unit tests run against these doubles instead. The stubs are
 * not PSR-4 autoloadable (they live in OpenEMR's namespaces, not the
 * module's), so they must be required before any module class that
 * references them loads.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Stubs/load.php';
