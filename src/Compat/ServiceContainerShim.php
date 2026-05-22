<?php

/**
 * Minimal ServiceContainer replacement for OpenEMR 7.x.
 *
 * The real BC\ServiceContainer was introduced in 8.x. This shim provides
 * only the getCrypto() method the ClaimRev module uses, returning a plain
 * CryptoGen instance.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Brad Sharp <brad.sharp@claimrev.com>
 * @copyright Copyright (c) 2026 Brad Sharp <brad.sharp@claimrev.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\ClaimRevConnector\Compat;

use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Common\Logging\SystemLogger;

class ServiceContainerShim
{
    /**
     * @return CryptoGen
     */
    public static function getCrypto()
    {
        return new CryptoGen();
    }

    /**
     * @return \Psr\Log\LoggerInterface
     */
    public static function getLogger()
    {
        return new SystemLogger();
    }

    /**
     * @return \Psr\Clock\ClockInterface|object
     */
    public static function getClock()
    {
        // Lcobucci\Clock may not exist on 7.x — return a simple wrapper
        if (class_exists(\Lcobucci\Clock\SystemClock::class)) {
            return \Lcobucci\Clock\SystemClock::fromSystemTimezone();
        }
        return new class () {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable();
            }
        };
    }
}
