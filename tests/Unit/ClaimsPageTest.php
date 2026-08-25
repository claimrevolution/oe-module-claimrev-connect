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

    public function testFetchClaimStatusesLetsApiFailuresPropagateToTheWrapper(): void
    {
        // The instance method throws; the static wrapper is what converts a
        // failure into an empty list, preserving long-standing behaviour.
        $factory = new MockApiFactory([new Response(503, [], 'down')]);

        $this->expectException(ClaimRevApiException::class);

        (new ClaimsPage($factory->api))->fetchClaimStatuses();
    }
}
