<?php

/**
 * PHPUnit bootstrap.
 *
 * Loads the Composer autoloader, then hand-written stubs for the slice of
 * OpenEMR core the module touches. OpenEMR is not installable as a dev
 * dependency, so unit tests run against these doubles instead.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// These classes are not PSR-4 autoloadable, so this must be required before
// any module class that references them loads, or PHP fatals on an
// undefined class.
require_once __DIR__ . '/Stubs/openemr-classes.php';
