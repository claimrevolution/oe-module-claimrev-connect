<?php

/**
 * How a stored ZITADEL key switches the module's configuration, and the
 * key store's behaviour around a missing table, the memo and encryption.
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Modules\ClaimRevConnector\ClaimRevAuthenticationException;
use OpenEMR\Modules\ClaimRevConnector\GlobalConfig;
use OpenEMR\Modules\ClaimRevConnector\ModuleNotConfiguredException;
use OpenEMR\Modules\ClaimRevConnector\Tests\Support\TestKeys;
use OpenEMR\Modules\ClaimRevConnector\ZitadelKeyStore;
use PHPUnit\Framework\TestCase;

final class ZitadelConfigTest extends TestCase
{
    protected function setUp(): void
    {
        \ClaimRevStubState::reset();
        ZitadelKeyStore::resetMemo();
    }

    /** Queue the hasKey() answer: existsTable (stub: true) then the key-id lookup. */
    private static function storedKey(bool $present): void
    {
        \ClaimRevStubState::$queryResults[] = $present ? '394411757516357635' : null;
    }

    private static function encrypt(string $plain): string
    {
        return (new CryptoGen())->encryptStandard($plain);
    }

    // --- GlobalConfig ---------------------------------------------------

    public function testStagingEnvironmentPointsAtTheStagingInstanceAndProject(): void
    {
        $config = new GlobalConfig([GlobalConfig::CONFIG_OPTION_ENVIRONMENT => 'S']);

        self::assertSame('https://auth-staging.claimrev.com', $config->getZitadelAuthority());
        self::assertSame('394262572515196931', $config->getZitadelProjectId());
        self::assertSame('https://testapi.claimrev.com', $config->getApiServer());
    }

    public function testProductionIsTheDefaultEnvironment(): void
    {
        $config = new GlobalConfig([]);

        self::assertSame('https://auth.claimrev.com', $config->getZitadelAuthority());
        self::assertSame('394259562464542723', $config->getZitadelProjectId());
    }

    public function testEnvironmentDUsesTheZitadelOverrides(): void
    {
        $config = new GlobalConfig([
            GlobalConfig::CONFIG_OPTION_ENVIRONMENT => 'D',
            GlobalConfig::CONFIG_OPTION_DEV_ZITADEL_AUTHORITY => 'https://auth.example.test',
            GlobalConfig::CONFIG_OPTION_DEV_ZITADEL_PROJECT => '42',
        ]);

        self::assertSame('https://auth.example.test', $config->getZitadelAuthority());
        self::assertSame('42', $config->getZitadelProjectId());
    }

    public function testEnvironmentDWithoutAnOverrideIsNotConfigured(): void
    {
        $config = new GlobalConfig([GlobalConfig::CONFIG_OPTION_ENVIRONMENT => 'D']);

        $this->expectException(ModuleNotConfiguredException::class);
        $config->getZitadelAuthority();
    }

    public function testAStoredKeyIsEnoughToBeConfiguredWithoutClientIdOrSecret(): void
    {
        self::storedKey(true);
        $config = new GlobalConfig([GlobalConfig::CONFIG_OPTION_ENVIRONMENT => 'S']);

        self::assertTrue($config->usesZitadel());
        self::assertTrue($config->isConfigured());
    }

    public function testWithoutAKeyTheB2cCredentialsAreStillRequired(): void
    {
        self::storedKey(false);
        $config = new GlobalConfig([
            GlobalConfig::CONFIG_OPTION_ENVIRONMENT => 'P',
            GlobalConfig::CONFIG_OPTION_CLIENTID => 'abc',
        ]);

        self::assertFalse($config->usesZitadel());
        self::assertFalse($config->isConfigured());
    }

    public function testB2cSitesStayConfiguredExactlyAsBefore(): void
    {
        self::storedKey(false);
        $config = new GlobalConfig([
            GlobalConfig::CONFIG_OPTION_ENVIRONMENT => 'P',
            GlobalConfig::CONFIG_OPTION_CLIENTID => 'abc',
            GlobalConfig::CONFIG_OPTION_CLIENTSECRET => 'encrypted-secret',
        ]);

        self::assertFalse($config->usesZitadel());
        self::assertTrue($config->isConfigured());
    }

    public function testAKeyDoesNotHelpWithoutAnEnvironment(): void
    {
        $config = new GlobalConfig([GlobalConfig::CONFIG_OPTION_ENVIRONMENT => '']);

        self::assertFalse($config->isConfigured());
    }

    // --- ZitadelKeyStore ------------------------------------------------

    public function testBeforeTheUpgradeRunsThereIsNoKeyAndNoFailingQuery(): void
    {
        \ClaimRevStubState::$missingTables = ['mod_claimrev_auth'];

        self::assertFalse(ZitadelKeyStore::hasKey());
        self::assertNull(ZitadelKeyStore::getKeyInfo());
        // Only the silent existence probe ran; no SELECT against the missing table.
        self::assertSame(['EXISTS TABLE mod_claimrev_auth'], array_column(\ClaimRevStubState::$queries, 'sql'));
    }

    public function testHasKeyQueriesOncePerRequest(): void
    {
        self::storedKey(true);

        self::assertTrue(ZitadelKeyStore::hasKey());
        self::assertTrue(ZitadelKeyStore::hasKey());
        self::assertCount(2, \ClaimRevStubState::$queries, 'existsTable + one lookup, then memoised');
    }

    public function testCachedTokenIsDecrypted(): void
    {
        \ClaimRevStubState::$queryResults[] = ['access_token' => self::encrypt('eyJ.token.sig')];

        self::assertSame('eyJ.token.sig', ZitadelKeyStore::getCachedToken());
        self::assertStringContainsString('access_token_expires_at > DATE_ADD(NOW()', \ClaimRevStubState::$queries[0]['sql']);
    }

    public function testNoCachedTokenMeansNull(): void
    {
        self::assertNull(ZitadelKeyStore::getCachedToken());
    }

    public function testSavedTokenIsStoredEncrypted(): void
    {
        ZitadelKeyStore::saveToken('eyJ.token.sig', 43199);

        $binds = \ClaimRevStubState::$queries[0]['binds'];
        self::assertSame(self::encrypt('eyJ.token.sig'), $binds[0]);
        self::assertSame(43199, $binds[1]);
    }

    public function testStoredKeyFileIsDecryptedAndValidated(): void
    {
        \ClaimRevStubState::$queryResults[] = self::encrypt(TestKeys::keyFileJson(['keyId' => '1', 'userId' => '2']));

        $key = ZitadelKeyStore::getKeyFile();

        self::assertSame('1', $key['keyId']);
        self::assertSame('2', $key['userId']);
    }

    public function testAnUndecryptableKeyIsAnAuthenticationFailure(): void
    {
        \ClaimRevStubState::$queryResults[] = 'not-encrypted-by-this-install';

        $this->expectException(ClaimRevAuthenticationException::class);
        ZitadelKeyStore::getKeyFile();
    }

    public function testNoStoredKeyIsAnAuthenticationFailure(): void
    {
        $this->expectException(ClaimRevAuthenticationException::class);
        ZitadelKeyStore::getKeyFile();
    }

    public function testAnInvalidKeyIsRejectedBeforeTouchingTheDatabase(): void
    {
        try {
            ZitadelKeyStore::saveKeyFile('{"clientId":"cr-x","secret":"pasted the wrong thing"}');
            self::fail('expected the key to be rejected');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame([], \ClaimRevStubState::$queries);
    }
}
