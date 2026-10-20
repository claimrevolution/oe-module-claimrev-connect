<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use OpenEMR\Modules\ClaimRevConnector\Dto\ClaimSearchResult;
use PHPUnit\Framework\TestCase;

/**
 * Field mapping for a ClaimRev claim-search result, using the field names the
 * live SearchClaimsPaged API actually returns.
 */
final class ClaimSearchResultTest extends TestCase
{
    public function testObjectIdIsKeptAsTheMongoHexString(): void
    {
        // Typed as int before, so every id parsed to null and the Claims tab
        // asked GetClaimErrors for claimId "" ("Failed to load errors").
        $result = ClaimSearchResult::fromApi(['objectId' => '6ad7b1ab5238759b1876ddbf']);

        self::assertSame('6ad7b1ab5238759b1876ddbf', $result->objectId);
    }

    public function testPatientControlNumberComesFromPatientAccountNumber(): void
    {
        // The claim view has no patientControlNumber field; CLM01 ("pid-encounter")
        // is returned as patientAccountNumber.
        $result = ClaimSearchResult::fromApi((object) ['patientAccountNumber' => '9103-990203']);

        self::assertSame('9103-990203', $result->patientControlNumber);
    }

    public function testExplicitPatientControlNumberWinsWhenPresent(): void
    {
        self::assertSame('1-2', ClaimSearchResult::patientControlNumberOf([
            'patientControlNumber' => '1-2',
            'patientAccountNumber' => '3-4',
        ]));
    }

    public function testEmptyPatientControlNumberFallsBackToAccountNumber(): void
    {
        self::assertSame('3-4', ClaimSearchResult::patientControlNumberOf([
            'patientControlNumber' => '',
            'patientAccountNumber' => '3-4',
        ]));
    }

    public function testNoPcnFieldsGivesNull(): void
    {
        self::assertNull(ClaimSearchResult::patientControlNumberOf([]));
    }
}
