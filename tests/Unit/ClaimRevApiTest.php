<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClaimRevConnector\ClaimRevApiException;
use OpenEMR\Modules\ClaimRevConnector\Tests\Support\MockApiFactory;
use PHPUnit\Framework\TestCase;

final class ClaimRevApiTest extends TestCase
{
    public function testSearchClaimsPostsToTheClaimViewEndpoint(): void
    {
        $factory = MockApiFactory::withJson(['results' => [], 'totalRecords' => 0]);

        $factory->api->searchClaims((object) ['patientLastName' => 'Sharp']);

        self::assertSame('/api/ClaimView/v1/SearchClaimsPaged', $factory->requestTarget());
        self::assertSame(['patientLastName' => 'Sharp'], $factory->requestBody());
    }

    public function testRequestsCarryTheBearerToken(): void
    {
        $factory = MockApiFactory::withJson([]);

        $factory->api->getClaimStatuses();

        self::assertSame('Bearer test-token', $factory->requestHeader('Authorization'));
    }

    /**
     * NOTE: Guzzle's default `http_errors: true` means a real client throws a
     * GuzzleException on any 4xx/5xx response before ClaimRevApi::get() ever
     * sees the response to check its status code itself. So the request
     * lands in the `catch (GuzzleException $e)` branch, not the
     * "returned HTTP {code}" branch — see
     * testGuzzleErrorPathLosesTheStatusCodeAndBody below for what that means
     * for httpStatusCode/responseBody.
     */
    public function testNon200ResponsesRaiseAnApiException(): void
    {
        $factory = new MockApiFactory([new Response(500, [], 'upstream exploded')]);

        $this->expectException(ClaimRevApiException::class);
        $this->expectExceptionMessage('ClaimRev API request failed:');

        $factory->api->getClaimStatuses();
    }

    /**
     * Documents a real gap: because Guzzle throws before ClaimRevApi's own
     * status-code check runs, httpStatusCode and responseBody on the raised
     * exception are always 0 and '' for genuine HTTP failures, even though
     * the upstream response (404, "nope") is known to Guzzle's exception.
     * Only $endpoint survives intact. Any caller inspecting
     * $e->httpStatusCode or $e->responseBody to report the real failure
     * reason will not get it.
     */
    public function testGuzzleErrorPathLosesTheStatusCodeAndBody(): void
    {
        $factory = new MockApiFactory([new Response(404, [], 'nope')]);

        try {
            $factory->api->getClaimStatuses();
            self::fail('Expected ClaimRevApiException');
        } catch (ClaimRevApiException $e) {
            self::assertSame(0, $e->httpStatusCode);
            self::assertSame('', $e->responseBody);
            self::assertSame('/api/ClaimView/v1/GetClaimStatuses', $e->endpoint);
            self::assertStringContainsString('404', $e->getMessage());
        }
    }
}
