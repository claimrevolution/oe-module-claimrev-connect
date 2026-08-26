<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClaimRevConnector\ClaimRevApiException;
use OpenEMR\Modules\ClaimRevConnector\ClaimsPage;
use OpenEMR\Modules\ClaimRevConnector\Tests\Support\MockApiFactory;
use PHPUnit\Framework\TestCase;

final class ClaimsPageTest extends TestCase
{
    public function testExportClaimsCsvPostsTheSearchModelAndReturnsTheFile(): void
    {
        $factory = MockApiFactory::withJson(['fileText' => 'a,b,c', 'fileName' => 'claims.csv']);

        $result = (new ClaimsPage($factory->api))->exportClaimsCsv(['patLastName' => 'Sharp']);

        self::assertSame('/api/ClaimView/v1/SearchClaimsCsv', $factory->requestTarget());
        self::assertSame('a,b,c', $result['fileText']);
        self::assertSame('claims.csv', $result['fileName']);
    }

    public function testExportClaimsCsvSendsTheMappedFilters(): void
    {
        $factory = MockApiFactory::withJson(['fileText' => '', 'fileName' => 'x.csv']);

        (new ClaimsPage($factory->api))->exportClaimsCsv([
            'patLastName' => 'Sharp',
            'payerNumber' => '99999',
        ]);

        $body = $factory->requestBody();
        self::assertSame('Sharp', $body['patientLastName']);
        self::assertSame('99999', $body['payerNumber']);
    }

    public function testExportClaimsCsvLetsApiFailuresPropagate(): void
    {
        $factory = new MockApiFactory([new Response(500, [], 'boom')]);

        $this->expectException(ClaimRevApiException::class);

        (new ClaimsPage($factory->api))->exportClaimsCsv([]);
    }

    public function testFetchClaimStatusesReturnsTheStatusList(): void
    {
        $factory = MockApiFactory::withJson([
            ['listItemId' => '1', 'listName' => 'Accepted'],
            ['listItemId' => '2', 'listName' => 'Rejected'],
        ]);

        $result = (new ClaimsPage($factory->api))->fetchClaimStatuses();

        self::assertSame('/api/ClaimView/v1/GetClaimStatuses', $factory->requestTarget());
        self::assertCount(2, $result);
        self::assertSame('Accepted', $result[0]['listName']);
    }

    public function testFetchClaimStatusesLetsApiFailuresReachItsCaller(): void
    {
        // Covers the instance method only. The static getClaimStatuses()
        // wrapper catches ClaimRevException and returns [] — long-standing
        // behaviour that silently empties the Claims-tab status dropdown
        // during an outage — but that wrapper is NOT exercised here: it calls
        // makeFromGlobals(), which needs a Kernel in globals that the stub
        // layer does not yet provide. That gap is recorded as a follow-up.
        $factory = new MockApiFactory([new Response(503, [], 'down')]);

        $this->expectException(ClaimRevApiException::class);

        (new ClaimsPage($factory->api))->fetchClaimStatuses();
    }
}
