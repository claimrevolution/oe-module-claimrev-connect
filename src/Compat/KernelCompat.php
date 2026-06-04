<?php

/**
 * Cross-version kernel resolver.
 *
 * Core `OEGlobalsBag::getKernel()` only exists on the OpenEMR flex/master
 * line; the 8.0.x patch releases (and 7.x) ship an OEGlobalsBag without it.
 * Because the real class is present on 8.0.x, the OEGlobalsBagShim swap never
 * activates there, so a direct `getKernel()` call fatals with
 * "Call to undefined method". This helper resolves the kernel the same way
 * flex's getKernel() does internally — `get('kernel')` with a type guard —
 * which works on every supported line (7.x, 8.0.x, flex).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Brad Sharp <brad.sharp@claimrev.com>
 * @copyright Copyright (c) 2026 Brad Sharp <brad.sharp@claimrev.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Compat;

use OpenEMR\Core\Kernel;
use OpenEMR\Core\OEGlobalsBag;
use RuntimeException;

final class KernelCompat
{
    public static function resolve(): Kernel
    {
        $kernel = OEGlobalsBag::getInstance()->get('kernel');
        if (!$kernel instanceof Kernel) {
            throw new RuntimeException('OpenEMR Kernel not initialized');
        }

        return $kernel;
    }
}
