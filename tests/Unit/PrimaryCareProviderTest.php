<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use OpenEMR\Modules\ClaimRevConnector\PrimaryCareProvider;
use PHPUnit\Framework\TestCase;

final class PrimaryCareProviderTest extends TestCase
{
    private const PCP = [
        'entityIdentifierCode' => 'P3',
        'lastOrganizationName' => 'Nguyen',
        'firstName' => 'Linh',
        'middleName' => 'T',
        'idQualifier' => 'XX',
        'identifier' => '1234567893',
        'address' => [
            'address1' => '100 Main St',
            'address2' => 'Suite 200',
            'city' => 'Tulsa',
            'state' => 'OK',
            'zip' => '741031234',
        ],
        'contacts' => [
            ['contactMethods' => [
                ['contactType' => 'FX', 'contactValue' => '9185550199'],
                ['contactType' => 'TE', 'contactValue' => '9185550100'],
            ]],
        ],
    ];

    /**
     * @param array<string, mixed> $mapped271
     */
    private static function eligibility(array $mapped271): object
    {
        $decoded = json_decode((string) json_encode(['status' => 'Active Coverage', 'mapped271' => $mapped271]));
        self::assertIsObject($decoded);
        return $decoded;
    }

    /**
     * @param list<array<string, mixed>> $entities
     * @return array<string, mixed>
     */
    private static function benefit(string $code, array $entities): array
    {
        return ['benefitInformation' => $code, 'relatedEntities' => $entities];
    }

    public function testExtractsAFullyPopulatedPcpFromTheSubscriber(): void
    {
        $result = PrimaryCareProvider::fromEligibility(self::eligibility([
            'subscriber' => ['benefits' => [self::benefit('L', [self::PCP])]],
        ]));

        self::assertSame([[
            'name' => 'Linh T Nguyen',
            'npi' => '1234567893',
            'address' => '100 Main St Suite 200, Tulsa, OK 74103-1234',
            'phone' => '(918) 555-0100',
        ]], $result);
    }

    public function testFindsThePcpUnderTheDependent(): void
    {
        $result = PrimaryCareProvider::fromEligibility(self::eligibility([
            'subscriber' => ['benefits' => [self::benefit('1', [])]],
            'dependent' => ['benefits' => [self::benefit('L', [self::PCP])]],
        ]));

        self::assertCount(1, $result);
        self::assertSame('1234567893', $result[0]['npi']);
    }

    public function testRepeatedPcpIsDeduplicatedByNpi(): void
    {
        $result = PrimaryCareProvider::fromEligibility(self::eligibility([
            'subscriber' => ['benefits' => [
                self::benefit('1', [self::PCP]),
                self::benefit('L', [self::PCP]),
            ]],
        ]));

        self::assertCount(1, $result);
    }

    public function testIgnoresRelatedEntitiesThatAreNotP3(): void
    {
        $other = ['entityIdentifierCode' => 'PR', 'lastOrganizationName' => 'Some Payer'] + self::PCP;

        $result = PrimaryCareProvider::fromEligibility(self::eligibility([
            'subscriber' => ['benefits' => [self::benefit('L', [$other])]],
        ]));

        self::assertSame([], $result);
    }

    public function testIgnoresTheTopLevelNameOnlyRelatedEntities(): void
    {
        $decoded = json_decode((string) json_encode([
            'relatedEntities' => [['name' => 'Dr Somebody']],
            'mapped271' => ['subscriber' => ['benefits' => []]],
        ]));

        self::assertSame([], PrimaryCareProvider::fromEligibility($decoded));
    }

    public function testOrganizationPcpWithoutNpiStillShowsByName(): void
    {
        $org = [
            'entityIdentifierCode' => 'P3',
            'lastOrganizationName' => 'Tulsa Family Clinic',
            'idQualifier' => 'PI',
            'identifier' => 'ABC123',
        ];

        $result = PrimaryCareProvider::fromEligibility(self::eligibility([
            'subscriber' => ['benefits' => [self::benefit('L', [$org, $org])]],
        ]));

        self::assertSame([[
            'name' => 'Tulsa Family Clinic',
            'npi' => '',
            'address' => '',
            'phone' => '',
        ]], $result);
    }

    public function testReturnsNothingWithoutMapped271(): void
    {
        self::assertSame([], PrimaryCareProvider::fromEligibility((object) ['status' => 'Active Coverage']));
        self::assertSame([], PrimaryCareProvider::fromEligibility(null));
    }

    public function testFiveDigitZipAndNonTenDigitPhoneAreLeftAlone(): void
    {
        self::assertSame('74103', PrimaryCareProvider::formatZip('74103'));
        self::assertSame('918-555-0100 x12', PrimaryCareProvider::formatPhone('918-555-0100 x12'));
    }
}
