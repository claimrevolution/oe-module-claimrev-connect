<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use OpenEMR\Modules\ClaimRevConnector\Tests\Support\TestKeys;
use OpenEMR\Modules\ClaimRevConnector\ZitadelAuth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ZitadelAuthTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function keyFileJson(array $overrides = []): string
    {
        return TestKeys::keyFileJson($overrides);
    }

    /** @return array<string, mixed> */
    private static function decodeSegment(string $segment): array
    {
        $decoded = json_decode(base64_decode(strtr($segment, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    public function testParseKeyFileReturnsTheFieldsTheGrantNeeds(): void
    {
        $key = ZitadelAuth::parseKeyFile($this->keyFileJson());

        self::assertSame('111', $key['keyId']);
        self::assertSame('222', $key['userId']);
        self::assertSame('2028-10-08T22:08:45Z', $key['expirationDate']);
        self::assertSame(TestKeys::privateKeyPem(), $key['key']);
    }

    public function testParseKeyFileAcceptsPrettyPrintedJsonWithSurroundingWhitespace(): void
    {
        $pretty = "\n  " . json_encode(json_decode($this->keyFileJson()), JSON_PRETTY_PRINT) . "\n";

        self::assertSame('111', ZitadelAuth::parseKeyFile($pretty)['keyId']);
    }

    public function testParseKeyFileTreatsAMissingExpiryAsNever(): void
    {
        $data = json_decode($this->keyFileJson(), true);
        unset($data['expirationDate']);

        self::assertSame('', ZitadelAuth::parseKeyFile(json_encode($data, JSON_THROW_ON_ERROR))['expirationDate']);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidKeyFiles(): iterable
    {
        yield 'not json' => ['client-secret-pasted-by-mistake'];
        yield 'json scalar' => ['"abc"'];
    }

    #[DataProvider('invalidKeyFiles')]
    public function testParseKeyFileRejectsNonJson(string $content): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ZitadelAuth::parseKeyFile($content);
    }

    public function testParseKeyFileRejectsOtherKeyTypes(): void
    {
        // An application (API app) key file has type "application" and a clientId.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('serviceaccount');
        ZitadelAuth::parseKeyFile($this->keyFileJson(['type' => 'application']));
    }

    public function testParseKeyFileRejectsAMissingUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('userId');
        ZitadelAuth::parseKeyFile($this->keyFileJson(['userId' => '']));
    }

    public function testParseKeyFileRejectsAnUnreadablePrivateKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('private key');
        ZitadelAuth::parseKeyFile($this->keyFileJson(['key' => "-----BEGIN RSA PRIVATE KEY-----\nnope\n-----END RSA PRIVATE KEY-----"]));
    }

    public function testAssertionIsAnRs256JwtSignedByTheKeyForTheServiceUser(): void
    {
        $key = ZitadelAuth::parseKeyFile($this->keyFileJson());

        $jwt = ZitadelAuth::buildAssertion($key, 'https://auth-staging.claimrev.com/', 1_700_000_000);

        $parts = explode('.', $jwt);
        self::assertCount(3, $parts);
        self::assertSame(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => '111'], self::decodeSegment($parts[0]));
        self::assertSame([
            'iss' => '222',
            'sub' => '222',
            'aud' => 'https://auth-staging.claimrev.com',
            'iat' => 1_700_000_000,
            'exp' => 1_700_000_300,
        ], self::decodeSegment($parts[1]));

        $signature = base64_decode(strtr($parts[2], '-_', '+/'));
        self::assertSame(1, openssl_verify($parts[0] . '.' . $parts[1], $signature, TestKeys::publicKeyPem(), OPENSSL_ALGO_SHA256));
    }

    public function testTokenRequestUsesTheJwtBearerGrantAndTheProjectAudienceScope(): void
    {
        $key = ZitadelAuth::parseKeyFile($this->keyFileJson());

        $form = ZitadelAuth::tokenRequestForm($key, 'https://auth.claimrev.com', '394259562464542723', 1_700_000_000);

        self::assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type']);
        self::assertSame('openid urn:zitadel:iam:org:project:id:394259562464542723:aud', $form['scope']);
        self::assertSame(
            ZitadelAuth::buildAssertion($key, 'https://auth.claimrev.com', 1_700_000_000),
            $form['assertion']
        );
        self::assertArrayNotHasKey('client_secret', $form);
    }

    public function testTokenEndpointIsUnderTheInstanceRoot(): void
    {
        self::assertSame('https://auth.claimrev.com/oauth/v2/token', ZitadelAuth::tokenEndpoint('https://auth.claimrev.com/'));
    }
}
