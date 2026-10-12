<?php

/**
 * ZITADEL service-user authentication: key-file parsing and the JWT-bearer
 * token request (RFC 7523).
 *
 * A ZITADEL client application is a service user that authenticates with a
 * downloadable JSON key file, not a client secret. The module signs a short
 * JWT with that key and exchanges it at the instance's token endpoint.
 *
 * Pure functions only (no DB, no HTTP) so they are unit-testable.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Brad Sharp <brad.sharp@claimrev.com>
 * @copyright Copyright (c) 2026 Brad Sharp <brad.sharp@claimrev.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector;

use SensitiveParameter;

final class ZitadelAuth
{
    public const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    /** Header the ClaimRev API uses to route a bearer token to its ZITADEL validator. */
    public const TENANT_HEADER = 'x-auth-tenant';
    public const TENANT_HINT = 'zitadel';

    /** Assertion lifetime; it is only used once, immediately. */
    private const ASSERTION_TTL_SECONDS = 300;

    /**
     * Parse and validate a ZITADEL service-user key file.
     *
     * @return array{keyId: string, key: string, userId: string, expirationDate: string}
     * @throws \InvalidArgumentException when the content is not a usable key file
     */
    public static function parseKeyFile(#[SensitiveParameter] string $json): array
    {
        try {
            $data = json_decode(trim($json), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('The key file is not valid JSON.');
        }
        if (!is_array($data)) {
            throw new \InvalidArgumentException('The key file is not valid JSON.');
        }
        if (($data['type'] ?? null) !== 'serviceaccount') {
            throw new \InvalidArgumentException('This is not a ZITADEL service-account key file (type must be "serviceaccount").');
        }
        foreach (['keyId', 'key', 'userId'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) || $data[$field] === '') {
                throw new \InvalidArgumentException("The key file is missing \"{$field}\".");
            }
        }
        $privateKey = openssl_pkey_get_private($data['key']);
        if ($privateKey === false) {
            throw new \InvalidArgumentException('The key file\'s private key could not be read.');
        }

        $expiration = $data['expirationDate'] ?? '';
        return [
            'keyId' => $data['keyId'],
            'key' => $data['key'],
            'userId' => $data['userId'],
            'expirationDate' => is_string($expiration) ? $expiration : '',
        ];
    }

    /**
     * Build the signed JWT assertion: iss = sub = the service user, aud = the instance issuer.
     *
     * @param array{keyId: string, key: string, userId: string} $keyFile
     */
    public static function buildAssertion(#[SensitiveParameter] array $keyFile, string $authority, int $now): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $keyFile['keyId']];
        $claims = [
            'iss' => $keyFile['userId'],
            'sub' => $keyFile['userId'],
            'aud' => rtrim($authority, '/'),
            'iat' => $now,
            'exp' => $now + self::ASSERTION_TTL_SECONDS,
        ];
        $signingInput = self::base64Url(json_encode($header, JSON_THROW_ON_ERROR))
            . '.' . self::base64Url(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $signature = '';
        if (!openssl_sign($signingInput, $signature, $keyFile['key'], OPENSSL_ALGO_SHA256)) {
            throw new ClaimRevAuthenticationException('Could not sign the ZITADEL assertion with the configured key.');
        }

        return $signingInput . '.' . self::base64Url($signature);
    }

    public static function tokenEndpoint(string $authority): string
    {
        return rtrim($authority, '/') . '/oauth/v2/token';
    }

    /**
     * Form body for the token request. The project audience scope puts the
     * ClaimRev API project into the access token's `aud`.
     *
     * @param array{keyId: string, key: string, userId: string} $keyFile
     * @return array<string, string>
     */
    public static function tokenRequestForm(#[SensitiveParameter] array $keyFile, string $authority, string $projectId, int $now): array
    {
        return [
            'grant_type' => self::GRANT_TYPE,
            'assertion' => self::buildAssertion($keyFile, $authority, $now),
            'scope' => "openid urn:zitadel:iam:org:project:id:{$projectId}:aud",
        ];
    }

    private static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
