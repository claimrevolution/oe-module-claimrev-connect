<?php

declare(strict_types=1);

namespace OpenEMR\Common\Crypto;

/**
 * Doubles OpenEMR\Common\Crypto\CryptoGen with a reversible, visibly-tagged
 * encoding, so tests can tell an encrypted value from plaintext and round-trip it.
 */
class CryptoGen
{
    public const PREFIX = 'enc:';

    public function encryptStandard(?string $value): string
    {
        return self::PREFIX . base64_encode((string) $value);
    }

    public function decryptStandard(?string $value): string|false
    {
        if ($value === null || !str_starts_with($value, self::PREFIX)) {
            return false;
        }
        return base64_decode(substr($value, strlen(self::PREFIX)), true);
    }
}
