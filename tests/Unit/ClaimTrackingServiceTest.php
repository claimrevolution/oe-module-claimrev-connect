<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use ClaimRevStubState;
use OpenEMR\Modules\ClaimRevConnector\ClaimTrackingService;
use OpenEMR\Modules\ClaimRevConnector\Tests\Support\MockApiFactory;
use PHPUnit\Framework\TestCase;

final class ClaimTrackingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        ClaimRevStubState::reset();
    }

    protected function tearDown(): void
    {
        ClaimRevStubState::reset();
    }

    public function testSyncBatchViaApiSendsEveryPcnInOneSearch(): void
    {
        $factory = MockApiFactory::withJson(['results' => [], 'totalRecords' => 0]);

        (new ClaimTrackingService($factory->api))->syncBatchViaApi(['1-1', '2-2']);

        self::assertSame('/api/ClaimView/v1/SearchClaimsPaged', $factory->requestTarget());
        self::assertSame(['1-1', '2-2'], $factory->requestBody()['patientControlNumbers']);
    }

    public function testSyncBatchViaApiShortCircuitsOnAnEmptyPcnList(): void
    {
        $factory = MockApiFactory::withJson(['results' => []]);

        $summary = (new ClaimTrackingService($factory->api))->syncBatchViaApi([]);

        self::assertSame(0, $factory->requestCount(), 'An empty list must not reach the API');
        self::assertSame(0, $summary['synced']);
        self::assertSame(0, $summary['errors']);
    }

    public function testBatchSyncReportsEveryPcnAsFailedWhenTheApiIsDown(): void
    {
        // The static wrapper owns this path: construction succeeds, the
        // instance call fails, and every requested PCN comes back tagged.
        $factory = new MockApiFactory([new \GuzzleHttp\Psr7\Response(500, [], 'boom')]);

        $summary = (new ClaimTrackingService($factory->api))->syncBatchViaApi(['1-1', '2-2']);

        self::assertSame(2, $summary['errors']);
        self::assertCount(2, $summary['results']);
        self::assertFalse($summary['results'][0]['success']);
        self::assertSame('ClaimRev connection failed', $summary['results'][0]['message']);
    }
}
