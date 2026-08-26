<?php

declare(strict_types=1);

namespace OpenEMR\Core;

/**
 * Doubles OpenEMR\Core\OEGlobalsBag, which extends Symfony's ParameterBag.
 *
 * Stateless by design: every read goes through ClaimRevStubState::$globals,
 * so ClaimRevStubState::reset() is sufficient. Do not add instance state
 * without updating reset().
 */
class OEGlobalsBag
{
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return \ClaimRevStubState::$globals[$key] ?? $default;
    }

    /** Mirrors ParameterBag::getString — casts scalars, throws otherwise. */
    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            throw new \UnexpectedValueException(
                sprintf('Parameter value "%s" cannot be converted to "string".', $key)
            );
        }
        return (string) $value;
    }
}
