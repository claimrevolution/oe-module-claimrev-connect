<?php

/**
 * Stores the ZITADEL service-user key file and the cached access token.
 *
 * Both live encrypted in the singleton row of mod_claimrev_auth. The key file
 * is ~2 KB, too big for an OpenEMR global (globals.gl_value is varchar(255)),
 * which is why it has its own table instead of a setting.
 *
 * A stored key is also what switches the module from B2C client credentials
 * to ZITADEL; see ClaimRevApi::makeFromGlobals().
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Brad Sharp <brad.sharp@claimrev.com>
 * @copyright Copyright (c) 2026 Brad Sharp <brad.sharp@claimrev.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Common\Database\QueryUtils;
use SensitiveParameter;

final class ZitadelKeyStore
{
    private const TABLE = 'mod_claimrev_auth';

    /** Refresh the cached token this long before it expires. */
    private const TOKEN_REFRESH_MARGIN_SECONDS = 600;

    /** Per-request memo for hasKey(); Bootstrap asks on every page load. */
    private static ?bool $hasKeyMemo = null;

    /**
     * True when a ZITADEL key is stored. Never throws: it runs on every request
     * via GlobalConfig::isConfigured(), and the table does not exist until the
     * module's migrations have run, so any failure means "no key".
     */
    public static function hasKey(): bool
    {
        if (self::$hasKeyMemo !== null) {
            return self::$hasKeyMemo;
        }
        try {
            // existsTable() probes without logging, so an install that has not run
            // "Run Upgrade" yet doesn't write an SQL error on every page load.
            if (!QueryUtils::existsTable(self::TABLE)) {
                return self::$hasKeyMemo = false;
            }
            $value = QueryUtils::fetchSingleValue(
                "SELECT zitadel_key_id FROM `" . self::TABLE . "` WHERE id = 1 AND zitadel_key IS NOT NULL AND zitadel_key <> ''",
                'zitadel_key_id'
            );
            self::$hasKeyMemo = is_string($value) && $value !== '';
        } catch (\Throwable) {
            self::$hasKeyMemo = false;
        }
        return self::$hasKeyMemo;
    }

    /** @internal Forget the per-request hasKey() answer; for tests. */
    public static function resetMemo(): void
    {
        self::$hasKeyMemo = null;
    }

    /**
     * Non-secret details of the stored key, for display.
     *
     * @return array{keyId: string, userId: string, keyExpires: string, updatedAt: string}|null
     */
    public static function getKeyInfo(): ?array
    {
        if (!self::hasKey()) {
            return null;
        }
        try {
            $row = QueryUtils::querySingleRow(
                "SELECT zitadel_key_id, zitadel_user_id, zitadel_key_expires, updated_at FROM `" . self::TABLE . "` "
                . "WHERE id = 1 AND zitadel_key IS NOT NULL AND zitadel_key <> ''"
            );
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($row)) {
            return null;
        }
        return [
            'keyId' => TypeCoerce::asString($row['zitadel_key_id'] ?? ''),
            'userId' => TypeCoerce::asString($row['zitadel_user_id'] ?? ''),
            'keyExpires' => TypeCoerce::asString($row['zitadel_key_expires'] ?? ''),
            'updatedAt' => TypeCoerce::asString($row['updated_at'] ?? ''),
        ];
    }

    /**
     * The decrypted, validated key file.
     *
     * @return array{keyId: string, key: string, userId: string, expirationDate: string}
     * @throws ClaimRevAuthenticationException when no usable key is stored
     */
    public static function getKeyFile(): array
    {
        $encrypted = QueryUtils::fetchSingleValue(
            "SELECT zitadel_key FROM `" . self::TABLE . "` WHERE id = 1",
            'zitadel_key'
        );
        if (!is_string($encrypted) || $encrypted === '') {
            throw new ClaimRevAuthenticationException('No ZITADEL key is configured.');
        }
        $json = self::crypto()->decryptStandard($encrypted);
        if (!is_string($json) || $json === '') {
            throw new ClaimRevAuthenticationException('The stored ZITADEL key could not be decrypted.');
        }
        try {
            return ZitadelAuth::parseKeyFile($json);
        } catch (\InvalidArgumentException $e) {
            throw new ClaimRevAuthenticationException('The stored ZITADEL key is invalid: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate and store a key file, replacing any previous key and its cached token.
     *
     * @throws \InvalidArgumentException when the content is not a usable key file
     */
    public static function saveKeyFile(#[SensitiveParameter] string $json): void
    {
        $key = ZitadelAuth::parseKeyFile($json);
        // Creates the table on installs that upgraded without running "Run Upgrade".
        ClaimRevModuleSetup::runMigrations();

        $encrypted = self::crypto()->encryptStandard(trim($json));
        QueryUtils::sqlStatementThrowException(
            "INSERT INTO `" . self::TABLE . "` (id, zitadel_key, zitadel_key_id, zitadel_user_id, zitadel_key_expires, access_token, access_token_expires_at, updated_at) "
            . "VALUES (1, ?, ?, ?, ?, NULL, NULL, NOW()) "
            . "ON DUPLICATE KEY UPDATE zitadel_key = VALUES(zitadel_key), zitadel_key_id = VALUES(zitadel_key_id), "
            . "zitadel_user_id = VALUES(zitadel_user_id), zitadel_key_expires = VALUES(zitadel_key_expires), "
            . "access_token = NULL, access_token_expires_at = NULL, updated_at = NOW()",
            [$encrypted, $key['keyId'], $key['userId'], $key['expirationDate']]
        );
        self::$hasKeyMemo = true;
    }

    /** Remove the key and cached token; the module falls back to B2C client credentials. */
    public static function removeKey(): void
    {
        QueryUtils::sqlStatementThrowException(
            "UPDATE `" . self::TABLE . "` SET zitadel_key = NULL, zitadel_key_id = '', zitadel_user_id = '', "
            . "zitadel_key_expires = '', access_token = NULL, access_token_expires_at = NULL, updated_at = NOW() WHERE id = 1"
        );
        self::$hasKeyMemo = false;
    }

    /** The cached access token, or null when absent or close to expiry. */
    public static function getCachedToken(): ?string
    {
        try {
            $row = QueryUtils::querySingleRow(
                "SELECT access_token FROM `" . self::TABLE . "` WHERE id = 1 AND access_token IS NOT NULL "
                . "AND access_token_expires_at > DATE_ADD(NOW(), INTERVAL " . self::TOKEN_REFRESH_MARGIN_SECONDS . " SECOND)"
            );
            if (!is_array($row) || !is_string($row['access_token'] ?? null) || $row['access_token'] === '') {
                return null;
            }
            $token = self::crypto()->decryptStandard($row['access_token']);
            return is_string($token) && $token !== '' ? $token : null;
        } catch (\Throwable) {
            // A cache miss only costs a token request.
            return null;
        }
    }

    public static function saveToken(#[SensitiveParameter] string $token, int $expiresInSeconds): void
    {
        try {
            QueryUtils::sqlStatementThrowException(
                "UPDATE `" . self::TABLE . "` SET access_token = ?, access_token_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = 1",
                [self::crypto()->encryptStandard($token), max(0, $expiresInSeconds)]
            );
        } catch (\Throwable) {
            // Caching is an optimisation; the token in hand is still good.
        }
    }

    private static function crypto(): CryptoGen
    {
        $crypto = ServiceContainer::getCrypto();
        if (!$crypto instanceof CryptoGen) {
            throw new \RuntimeException('ServiceContainer::getCrypto() did not return a CryptoGen instance');
        }
        return $crypto;
    }
}
