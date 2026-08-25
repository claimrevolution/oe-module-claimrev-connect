<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use ClaimRevStubState;
use OpenEMR\Billing\BillingProcessor\X12RemoteTracker;
use OpenEMR\Modules\ClaimRevConnector\ClaimUpload;
use OpenEMR\Modules\ClaimRevConnector\Tests\Support\MockApiFactory;
use PHPUnit\Framework\TestCase;

final class ClaimUploadTest extends TestCase
{
    private string $siteDir;

    protected function setUp(): void
    {
        ClaimRevStubState::reset();
        $this->siteDir = sys_get_temp_dir() . '/claimrev-upload-' . bin2hex(random_bytes(6));
        mkdir($this->siteDir . '/documents/edi', 0777, true);
        ClaimRevStubState::$globals['OE_SITE_DIR'] = $this->siteDir;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->siteDir . '/documents/edi/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->siteDir . '/documents/edi');
        @rmdir($this->siteDir . '/documents');
        @rmdir($this->siteDir);
        ClaimRevStubState::reset();
    }

    public function testUploadWaitingFilesSendsEachClaimFileAndMarksItSuccessful(): void
    {
        file_put_contents($this->siteDir . '/documents/edi/claim1.txt', 'ISA*00*CLAIM');
        $tracker = new X12RemoteTracker();
        $rows = [[
            'x12_filename' => 'claim1.txt',
            'x12_sftp_local_dir' => $this->siteDir . '/documents/edi/',
            'status' => 'waiting',
        ]];
        $factory = MockApiFactory::withJson([]);

        (new ClaimUpload($factory->api))->uploadWaitingFiles($tracker, $rows);

        self::assertSame('/api/InputFile/v1', $factory->requestTarget());
        $statuses = array_column(ClaimRevStubState::$x12Updates, 'status');
        self::assertContains(ClaimUpload::STATUS_SUCCESS, $statuses);
    }

    public function testUploadWaitingFilesMarksTheRowWhenTheFileCannotBeRead(): void
    {
        $tracker = new X12RemoteTracker();
        $rows = [[
            'x12_filename' => 'missing.txt',
            'x12_sftp_local_dir' => $this->siteDir . '/documents/edi/',
            'status' => 'waiting',
        ]];
        $factory = MockApiFactory::withJson([]);

        // file_get_contents() emits a native E_WARNING for the expected
        // missing file; production code does not suppress it, so the test
        // does instead rather than let PHPUnit's failOnWarning fail the run.
        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            (new ClaimUpload($factory->api))->uploadWaitingFiles($tracker, $rows);
        } finally {
            restore_error_handler();
        }

        self::assertSame(0, $factory->requestCount(), 'An unreadable file must not be uploaded');
        $statuses = array_column(ClaimRevStubState::$x12Updates, 'status');
        self::assertContains(ClaimUpload::STATUS_CLAIM_FILE_ERROR, $statuses);
    }
}
