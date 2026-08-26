<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClaimRevConnector\ClaimRevApiException;
use OpenEMR\Modules\ClaimRevConnector\ReconciliationService;
use OpenEMR\Modules\ClaimRevConnector\Tests\Support\MockApiFactory;
use PHPUnit\Framework\TestCase;

final class ReconciliationServiceTest extends TestCase
{
    public function testFetchClaimsByPcnsSendsEveryPcnAndPagesToMatch(): void
    {
        $factory = MockApiFactory::withJson(['results' => [], 'totalRecords' => 0]);

        (new ReconciliationService($factory->api))->fetchClaimsByPcns(['1-1', '2-2', '3-3']);

        $body = $factory->requestBody();
        self::assertSame('/api/ClaimView/v1/SearchClaimsPaged', $factory->requestTarget());
        self::assertSame(['1-1', '2-2', '3-3'], $body['patientControlNumbers']);
        self::assertSame(3, $body['pagingSearch']['pageSize'], 'Page size must cover every requested PCN');
    }

    public function testFetchClaimsByPcnsReturnsOnlyArrayEntries(): void
    {
        $factory = MockApiFactory::withJson([
            'results' => [['patientControlNumber' => '1-1'], 'not-an-array', ['patientControlNumber' => '2-2']],
        ]);

        $result = (new ReconciliationService($factory->api))->fetchClaimsByPcns(['1-1', '2-2']);

        self::assertCount(2, $result, 'Non-array entries must be filtered out');
        self::assertSame('1-1', $result[0]['patientControlNumber']);
    }

    public function testFetchClaimsByPcnsReturnsEmptyWhenResultsIsNotAnArray(): void
    {
        $factory = MockApiFactory::withJson(['results' => 'unexpected']);

        self::assertSame([], (new ReconciliationService($factory->api))->fetchClaimsByPcns(['1-1']));
    }

    public function testFetchClaimsByPcnsLetsApiFailuresPropagate(): void
    {
        // reconcile()'s own catch converts this into the partial-results
        // banner; the instance method itself must not swallow it.
        $factory = new MockApiFactory([new Response(500, [], 'boom')]);

        $this->expectException(ClaimRevApiException::class);

        (new ReconciliationService($factory->api))->fetchClaimsByPcns(['1-1']);
    }
}
