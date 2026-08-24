<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use ClaimRevStubState;
use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClaimRevConnector\ReportDownload;
use OpenEMR\Modules\ClaimRevConnector\Tests\Support\MockApiFactory;
use PHPUnit\Framework\TestCase;

final class ReportDownloadTest extends TestCase
{
    private string $siteDir;

    protected function setUp(): void
    {
        ClaimRevStubState::reset();
        $this->siteDir = sys_get_temp_dir() . '/claimrev-test-' . bin2hex(random_bytes(6));
        mkdir($this->siteDir, 0777, true);
        ClaimRevStubState::$globals['OE_SITE_DIR'] = $this->siteDir;
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->siteDir);
        ClaimRevStubState::reset();
    }

    private function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->deleteTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testSave835WritesTheFileTextToTheEraDirectory(): void
    {
        $factory = MockApiFactory::withJson(['fileText' => 'ISA*00*ERA-BODY']);

        (new ReportDownload($factory->api))->save835('era-1');

        $written = $this->siteDir . '/documents/era/era-1.edi';
        self::assertFileExists($written);
        self::assertSame('ISA*00*ERA-BODY', file_get_contents($written));
    }

    public function testSave835LogsAnErrorWhenFileTextIsAbsent(): void
    {
        $factory = MockApiFactory::withJson(['somethingElse' => true]);

        (new ReportDownload($factory->api))->save835('era-1');

        self::assertFileDoesNotExist($this->siteDir . '/documents/era/era-1.edi');
        self::assertSame('error', ClaimRevStubState::$logs[0]['level']);
        self::assertStringContainsString('fileText', ClaimRevStubState::$logs[0]['message']);
    }

    public function testSave835LogsAndReturnsWhenTheApiFails(): void
    {
        $factory = new MockApiFactory([new Response(500, [], 'boom')]);

        (new ReportDownload($factory->api))->save835('era-1');

        self::assertFileDoesNotExist($this->siteDir . '/documents/era/era-1.edi');
        self::assertSame('error', ClaimRevStubState::$logs[0]['level']);
        self::assertStringContainsString('Unable to download file', ClaimRevStubState::$logs[0]['message']);
    }

    public function testSaveWaitingFilesWritesEachReportUnderItsTypeFolder(): void
    {
        // Two requests: one per report type, 999 then 277.
        $factory = new MockApiFactory([
            new Response(200, [], json_encode([['fileText' => 'NINE-NINE-NINE', 'fileName' => 'r999']], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([['fileText' => 'TWO-SEVEN-SEVEN', 'fileName' => 'r277']], JSON_THROW_ON_ERROR)),
        ]);

        (new ReportDownload($factory->api))->saveWaitingFiles();

        // 999 reports land in f997, which is intentional and long-standing.
        self::assertSame(
            'NINE-NINE-NINE',
            file_get_contents($this->siteDir . '/documents/edi/history/f997/r999.txt'),
        );
        self::assertSame(
            'TWO-SEVEN-SEVEN',
            file_get_contents($this->siteDir . '/documents/edi/history/f277/r277.txt'),
        );
    }

    public function testSaveWaitingFilesContinuesToTheNextReportTypeWhenOneFails(): void
    {
        // saveWaitingFiles() promises that one failing report type does not
        // stop the others. 999 fails here, so 277 must still be written.
        $factory = new MockApiFactory([
            new Response(500, [], 'boom'),
            new Response(200, [], json_encode([['fileText' => 'TWO-SEVEN-SEVEN', 'fileName' => 'r277']], JSON_THROW_ON_ERROR)),
        ]);

        (new ReportDownload($factory->api))->saveWaitingFiles();

        self::assertFileDoesNotExist($this->siteDir . '/documents/edi/history/f997/r999.txt');
        self::assertSame(
            'TWO-SEVEN-SEVEN',
            file_get_contents($this->siteDir . '/documents/edi/history/f277/r277.txt'),
        );
        // The per-report-type failure is swallowed by a bare `continue`, with
        // no log entry. Pinned so that silence stays a deliberate choice.
        self::assertSame([], ClaimRevStubState::$logs);
    }
}
