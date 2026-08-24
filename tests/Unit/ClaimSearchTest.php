<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClaimRevConnector\ClaimRevApiException;
use OpenEMR\Modules\ClaimRevConnector\ClaimSearch;
use OpenEMR\Modules\ClaimRevConnector\Tests\Support\MockApiFactory;
use PHPUnit\Framework\TestCase;

/**
 * Note: the static ClaimSearch::search() wrapper itself is not directly
 * tested here. It calls ClaimRevApi::makeFromGlobals(), which needs OpenEMR
 * globals not stubbed until a later phase. Its two branches (configured vs.
 * ModuleNotConfiguredException) are exercised indirectly through the
 * instance method searchClaims(), which contains all of its logic.
 */
final class ClaimSearchTest extends TestCase
{
    public function testSearchClaimsReturnsTheDecodedPayload(): void
    {
        $factory = MockApiFactory::withJson([
            'results' => [['objectId' => 'abc-123']],
            'totalRecords' => 1,
        ]);

        $result = (new ClaimSearch($factory->api))
            ->searchClaims((object) ['patientLastName' => 'Sharp']);

        self::assertSame(1, $result['totalRecords']);
        self::assertSame('abc-123', $result['results'][0]['objectId']);
    }

    public function testSearchClaimsSendsTheModelAsTheRequestBody(): void
    {
        $factory = MockApiFactory::withJson(['results' => [], 'totalRecords' => 0]);

        (new ClaimSearch($factory->api))
            ->searchClaims((object) ['payerNumber' => '99999']);

        self::assertSame('/api/ClaimView/v1/SearchClaimsPaged', $factory->requestTarget());
        self::assertSame(['payerNumber' => '99999'], $factory->requestBody());
    }

    /**
     * Regression guard: an outage must not read as "no matches". The static
     * search() wrapper deliberately catches only ModuleNotConfiguredException,
     * so a ClaimRevApiException here reaches public/claims.php's error
     * banner instead of being swallowed into an empty result set.
     */
    public function testSearchClaimsLetsApiFailuresPropagate(): void
    {
        $factory = new MockApiFactory([new Response(503, [], 'unavailable')]);

        $this->expectException(ClaimRevApiException::class);

        (new ClaimSearch($factory->api))->searchClaims((object) []);
    }
}
