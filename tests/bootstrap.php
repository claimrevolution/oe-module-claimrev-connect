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

// Must load before any module class, so the stubbed OpenEMR classes win.
require_once __DIR__ . '/Stubs/openemr-classes.php';
