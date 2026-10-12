<?php

/**
 * A throwaway RSA key pair and ZITADEL-shaped key file for tests, generated
 * once per run.
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Support;

final class TestKeys
{
    private static ?string $privateKeyPem = null;
    private static ?string $publicKeyPem = null;

    public static function privateKeyPem(): string
    {
        self::generate();
        return (string) self::$privateKeyPem;
    }

    public static function publicKeyPem(): string
    {
        self::generate();
        return (string) self::$publicKeyPem;
    }

    /** @param array<string, mixed> $overrides */
    public static function keyFileJson(array $overrides = []): string
    {
        return json_encode(array_merge([
            'type' => 'serviceaccount',
            'keyId' => '111',
            'key' => self::privateKeyPem(),
            'expirationDate' => '2028-10-08T22:08:45Z',
            'userId' => '222',
        ], $overrides), JSON_THROW_ON_ERROR);
    }

    private static function generate(): void
    {
        if (self::$privateKeyPem !== null) {
            return;
        }
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        // Windows PHP builds (e.g. XAMPP) cannot generate keys without an explicit openssl.cnf.
        $bundledConfig = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        if (getenv('OPENSSL_CONF') === false && is_file($bundledConfig)) {
            $options['config'] = $bundledConfig;
        }
        $key = openssl_pkey_new($options);
        if ($key === false) {
            throw new \RuntimeException('openssl could not generate a test key: ' . openssl_error_string());
        }
        openssl_pkey_export($key, $pem, null, $options);
        $details = openssl_pkey_get_details($key);
        if (!is_string($pem) || !is_array($details)) {
            throw new \RuntimeException('openssl could not export the test key');
        }
        self::$privateKeyPem = $pem;
        self::$publicKeyPem = $details['key'];
    }
}
