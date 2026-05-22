<?php

/**
 * Minimal OEGlobalsBag replacement for OpenEMR 7.x.
 *
 * Wraps the $GLOBALS superglobal with the subset of methods that the
 * ClaimRev module actually uses. On 8.x this class is never loaded —
 * the real OEGlobalsBag takes its place.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Brad Sharp <brad.sharp@claimrev.com>
 * @copyright Copyright (c) 2026 Brad Sharp <brad.sharp@claimrev.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\ClaimRevConnector\Compat;

class OEGlobalsBagShim
{
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return array_key_exists($key, $GLOBALS) ? $GLOBALS[$key] : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $GLOBALS);
    }

    public function set(string $key, $value): void
    {
        $GLOBALS[$key] = $value;
    }

    public function getString(string $key, string $default = ''): string
    {
        return (string) $this->get($key, $default);
    }

    public function getInt(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    public function getBoolean(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }

    /**
     * @return \OpenEMR\Core\Kernel
     * @throws \RuntimeException if the kernel is not initialized
     */
    public function getKernel(): object
    {
        $kernel = $this->get('kernel');
        if (!($kernel instanceof \OpenEMR\Core\Kernel)) {
            throw new \RuntimeException('OpenEMR Kernel not initialized');
        }
        return $kernel;
    }

    public function hasKernel(): bool
    {
        return $this->get('kernel') instanceof \OpenEMR\Core\Kernel;
    }
}
