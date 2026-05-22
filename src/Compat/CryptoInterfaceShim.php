<?php

/**
 * Stub CryptoInterface for OpenEMR 7.x.
 *
 * On 7.x CryptoGen exists but doesn't implement an interface.
 * This empty interface satisfies the class loading if CryptoInterface
 * is referenced but doesn't exist.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Brad Sharp <brad.sharp@claimrev.com>
 * @copyright Copyright (c) 2026 Brad Sharp <brad.sharp@claimrev.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\ClaimRevConnector\Compat;

interface CryptoInterfaceShim
{
    // Intentionally empty — just satisfies the type system
}
