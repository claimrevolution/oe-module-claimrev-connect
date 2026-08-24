<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClaimRevConnector\ClaimRevApiException;
use OpenEMR\Modules\ClaimRevConnector\EraSearch;
use OpenEMR\Modules\ClaimRevConnector\Tests\Support\MockApiFactory;
use PHPUnit\Framework\TestCase;

final class EraSearchTest extends TestCase
{
    public function testSearchDownloadableFilesPostsToFileManagement(): void
    {
        $factory = MockApiFactory::withJson([['id' => 'era-1', 'payerName' => 'Acme Health']]);

        $result = (new EraSearch($factory->api))
            ->searchDownloadableFiles((object) ['ediType' => '835']);

        self::assertSame('/FileManagement/SearchOutboundClientFiles', $factory->requestTarget());
        self::assertSame(['ediType' => '835'], $factory->requestBody());
        self::assertSame('era-1', $result[0]['id']);
    }

    public function testFetchFileForDownloadPassesTheObjectIdAsQuery(): void
    {
        $factory = MockApiFactory::withJson(['fileText' => 'ISA*00*...', 'ediType' => '835']);

        $result = (new EraSearch($factory->api))->fetchFileForDownload('era-1');

        self::assertSame('/FileManagement/GetFileForDownload?id=era-1', $factory->requestTarget());
        self::assertSame('ISA*00*...', $result['fileText']);
    }

    public function testSearchDownloadableFilesLetsApiFailuresPropagate(): void
    {
        $factory = new MockApiFactory([new Response(500, [], 'boom')]);

        $this->expectException(ClaimRevApiException::class);

        (new EraSearch($factory->api))->searchDownloadableFiles((object) ['ediType' => '835']);
    }

    public function testFetchFileForDownloadLetsApiFailuresPropagate(): void
    {
        $factory = new MockApiFactory([new Response(500, [], 'boom')]);

        $this->expectException(ClaimRevApiException::class);

        (new EraSearch($factory->api))->fetchFileForDownload('era-1');
    }
}
