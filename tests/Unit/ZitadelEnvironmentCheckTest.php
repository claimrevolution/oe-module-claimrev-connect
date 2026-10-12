<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClaimRevConnector\Tests\Support\TestKeys;
use OpenEMR\Modules\ClaimRevConnector\ZitadelAuth;
use OpenEMR\Modules\ClaimRevConnector\ZitadelEnvironmentCheck;
use PHPUnit\Framework\TestCase;

final class ZitadelEnvironmentCheckTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $history = [];

    /** @param list<Response|\Throwable> $responses */
    private function check(array $responses): ZitadelEnvironmentCheck
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return new ZitadelEnvironmentCheck(new Client(['handler' => $stack]));
    }

    /** @return list<string> Hosts the check asked for a token, in order. */
    private function probedHosts(): array
    {
        return array_map(
            static function (array $transaction): string {
                $request = $transaction['request'];
                self::assertInstanceOf(Request::class, $request);
                return $request->getUri()->getHost();
            },
            $this->history
        );
    }

    /** @return array{keyId: string, key: string, userId: string, expirationDate: string} */
    private static function key(): array
    {
        return ZitadelAuth::parseKeyFile(TestKeys::keyFileJson());
    }

    private static function tokenResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"x","expires_in":43199}');
    }

    private static function rejected(): Response
    {
        return new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}');
    }

    public function testAStagingKeyOnAProductionSiteIsFoundOnStaging(): void
    {
        $check = $this->check([self::tokenResponse()]);

        self::assertSame('S', $check->findEnvironment(self::key(), 'P'));
        // Production already rejected it, so only staging is asked.
        self::assertSame(['auth-staging.claimrev.com'], $this->probedHosts());
    }

    public function testAProductionKeyOnAStagingSiteIsFoundOnProduction(): void
    {
        $check = $this->check([self::tokenResponse()]);

        self::assertSame('P', $check->findEnvironment(self::key(), 'S'));
        self::assertSame(['auth.claimrev.com'], $this->probedHosts());
    }

    public function testACustomEnvironmentSiteHasBothBuiltInInstancesProbed(): void
    {
        $check = $this->check([self::rejected(), self::tokenResponse()]);

        self::assertSame('S', $check->findEnvironment(self::key(), 'D'));
        self::assertSame(['auth.claimrev.com', 'auth-staging.claimrev.com'], $this->probedHosts());
    }

    public function testAKeyNoInstanceAcceptsGivesNoHint(): void
    {
        // e.g. a revoked key: the problem is the key, not the environment.
        $check = $this->check([self::rejected()]);

        self::assertNull($check->findEnvironment(self::key(), 'P'));
    }

    public function testAnUnreachableInstanceIsSkippedNotFatal(): void
    {
        $check = $this->check([
            new ConnectException('connection refused', new Request('POST', 'https://auth.claimrev.com/oauth/v2/token')),
            self::tokenResponse(),
        ]);

        self::assertSame('S', $check->findEnvironment(self::key(), 'D'));
    }

    public function testTheProbeIsARealJwtBearerTokenRequestForThatInstance(): void
    {
        $check = $this->check([self::tokenResponse()]);

        $check->findEnvironment(self::key(), 'P');

        $request = $this->history[0]['request'];
        self::assertInstanceOf(Request::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://auth-staging.claimrev.com/oauth/v2/token', (string) $request->getUri());
        parse_str((string) $request->getBody(), $form);
        self::assertSame(ZitadelAuth::GRANT_TYPE, $form['grant_type']);
        self::assertSame('openid urn:zitadel:iam:org:project:id:394262572515196931:aud', $form['scope']);
    }

    public function testTheMismatchMessageSaysWhatToChange(): void
    {
        $message = ZitadelEnvironmentCheck::describeMismatch('S', 'P');

        self::assertStringContainsString('Staging environment (S)', $message);
        self::assertStringContainsString('set to Production (P)', $message);
        self::assertStringContainsString('Change ClaimRev Environment to S', $message);
    }
}
