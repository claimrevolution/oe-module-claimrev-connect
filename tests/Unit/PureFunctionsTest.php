<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use OpenEMR\Modules\ClaimRevConnector\ClaimTrackingService;
use OpenEMR\Modules\ClaimRevConnector\NotificationPollService;
use OpenEMR\Modules\ClaimRevConnector\PaymentAdvicePage;
use OpenEMR\Modules\ClaimRevConnector\PaymentAdvicePostingService;
use OpenEMR\Modules\ClaimRevConnector\ReconciliationService;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the module's side-effect-free helpers.
 *
 * These need no DI seam and no stubs — they are pure functions that were
 * simply never tested. Grouped in one class because individually they are
 * too small to justify a file each.
 */
final class PureFunctionsTest extends TestCase
{
    // --- PaymentAdvicePostingService::parsePatientControlNumber() ----------

    public function testParsePatientControlNumberSplitsPidAndEncounter(): void
    {
        self::assertSame(
            ['pid' => 42, 'encounter' => 7],
            PaymentAdvicePostingService::parsePatientControlNumber('42-7'),
        );
    }

    public function testParsePatientControlNumberReturnsNullWhenThereIsNoSeparator(): void
    {
        // No space or hyphen means preg_split yields a single part, so the
        // count(...) < 2 guard fires and the function returns null, not [].
        self::assertNull(PaymentAdvicePostingService::parsePatientControlNumber('nonsense'));
    }

    public function testParsePatientControlNumberReturnsNullForANonPositivePid(): void
    {
        self::assertNull(PaymentAdvicePostingService::parsePatientControlNumber('0-5'));
    }

    // --- PaymentAdvicePostingService::buildIdempotencyReference() ----------

    public function testBuildIdempotencyReferencePrefixesTheAdviceId(): void
    {
        self::assertSame('ClaimRev-pa-1', PaymentAdvicePostingService::buildIdempotencyReference('pa-1'));
    }

    public function testBuildIdempotencyReferenceIsStableAndDistinctPerInput(): void
    {
        self::assertSame(
            PaymentAdvicePostingService::buildIdempotencyReference('pa-1'),
            PaymentAdvicePostingService::buildIdempotencyReference('pa-1'),
        );
        self::assertNotSame(
            PaymentAdvicePostingService::buildIdempotencyReference('pa-1'),
            PaymentAdvicePostingService::buildIdempotencyReference('pa-2'),
        );
    }

    // --- PaymentAdvicePostingService::getClaimStatusLabel() ----------------

    public function testGetClaimStatusLabelResolvesAKnownCode(): void
    {
        self::assertSame('Denied', PaymentAdvicePostingService::getClaimStatusLabel('4'));
    }

    public function testGetClaimStatusLabelFallsBackToTheRawCodeWhenUnknown(): void
    {
        // Numeric but outside the 835 vocabulary: falls through to the raw
        // (string) code rather than something like "Unknown".
        self::assertSame('99', PaymentAdvicePostingService::getClaimStatusLabel('99'));
    }

    public function testGetClaimStatusLabelPassesThroughNonNumericCodesUnchanged(): void
    {
        self::assertSame('ABC', PaymentAdvicePostingService::getClaimStatusLabel('ABC'));
    }

    // --- PaymentAdvicePostingService::sumServiceAmounts() -------------------

    public function testSumServiceAmountsTotalsChargesPaymentsAndAdjustments(): void
    {
        $services = [
            [
                'chargeAmount' => 100.0,
                'paymentAmount' => 80.0,
                'adjustmentGroups' => [
                    ['adjustments' => [['adjustmentAmount' => 10.0], ['adjustmentAmount' => 10.0]]],
                ],
            ],
            [
                'chargeAmount' => 50.0,
                'paymentAmount' => 50.0,
            ],
        ];

        self::assertSame(
            ['billed' => 150.0, 'paid' => 130.0, 'adjusted' => 20.0],
            PaymentAdvicePostingService::sumServiceAmounts($services),
        );
    }

    public function testSumServiceAmountsReturnsZeroesForAnEmptyList(): void
    {
        self::assertSame(
            ['billed' => 0.0, 'paid' => 0.0, 'adjusted' => 0.0],
            PaymentAdvicePostingService::sumServiceAmounts([]),
        );
    }

    // --- ClaimTrackingService::parsePcn() -----------------------------------

    public function testParsePcnSplitsPidAndEncounter(): void
    {
        self::assertSame(['pid' => 42, 'encounter' => 7], ClaimTrackingService::parsePcn('42-7'));
    }

    public function testParsePcnReturnsNullWhenThereIsNoSeparator(): void
    {
        self::assertNull(ClaimTrackingService::parsePcn('nonsense'));
    }

    // --- NotificationPollService::htmlToPlainText() -------------------------

    public function testHtmlToPlainTextPreservesParagraphBreaks(): void
    {
        $html = '<p>First para</p><p>Second para</p>';

        self::assertSame("First para\n\nSecond para", NotificationPollService::htmlToPlainText($html));
    }

    public function testHtmlToPlainTextStripsScriptAndStyleContent(): void
    {
        $html = '<style>.x{color:red}</style><script>alert(1)</script><p>Visible</p>';

        $text = NotificationPollService::htmlToPlainText($html);

        self::assertStringNotContainsString('color:red', $text);
        self::assertStringNotContainsString('alert(1)', $text);
        self::assertStringContainsString('Visible', $text);
    }

    public function testHtmlToPlainTextReturnsEmptyStringForEmptyInput(): void
    {
        self::assertSame('', NotificationPollService::htmlToPlainText(''));
    }

    // --- PaymentAdvicePage::normalizeAdvice() -------------------------------

    public function testNormalizeAdviceMapsEveryFieldOfAFullEntry(): void
    {
        $entry = [
            'paymentAdviceId' => 'pa-1',
            'receivedDate' => '2026-01-01',
            'payerName' => 'Acme Payer',
            'payerNumber' => '12345',
            'eraClassification' => 'Paid',
            'paymentInfo' => [
                'patientFirstName' => 'Jane',
                'patientLastName' => 'Doe',
                'patientControlNumber' => '42-7',
                'claimStatusCode' => '1',
                'totalClaimAmount' => 100.5,
                'claimPaymentAmount' => 80.25,
                'patientResponsibility' => 20.25,
                'isWorked' => true,
            ],
            'checkInformation' => [
                'checkNumber' => 'CHK-1',
                'checkDate' => '2026-01-02',
                'paymentMethodCode' => 'ACH',
                'paymentAmount' => 80.25,
            ],
        ];

        self::assertSame(
            [
                'paymentAdviceId' => 'pa-1',
                'receivedDate' => '2026-01-01',
                'payerName' => 'Acme Payer',
                'payerNumber' => '12345',
                'eraClassification' => 'Paid',
                'paymentInfo' => [
                    'patientFirstName' => 'Jane',
                    'patientLastName' => 'Doe',
                    'patientControlNumber' => '42-7',
                    'claimStatusCode' => '1',
                    'totalClaimAmount' => 100.5,
                    'claimPaymentAmount' => 80.25,
                    'patientResponsibility' => 20.25,
                    'isWorked' => true,
                ],
                'checkInformation' => [
                    'checkNumber' => 'CHK-1',
                    'checkDate' => '2026-01-02',
                    'paymentMethodCode' => 'ACH',
                    'paymentAmount' => 80.25,
                ],
            ],
            PaymentAdvicePage::normalizeAdvice($entry),
        );
    }

    public function testNormalizeAdviceFillsInDefaultsForASparseEntryWithoutNoticesOrWarnings(): void
    {
        // No keys at all: every ?? fallback must fire, and PHPUnit converts
        // any stray notice/warning into a failure, so a clean pass here is
        // itself the assertion that nothing is accessed unguarded.
        self::assertSame(
            [
                'paymentAdviceId' => '',
                'receivedDate' => '',
                'payerName' => '',
                'payerNumber' => '',
                'eraClassification' => '',
                'paymentInfo' => [
                    'patientFirstName' => '',
                    'patientLastName' => '',
                    'patientControlNumber' => '',
                    'claimStatusCode' => '',
                    'totalClaimAmount' => 0.0,
                    'claimPaymentAmount' => 0.0,
                    'patientResponsibility' => 0.0,
                    'isWorked' => false,
                ],
                'checkInformation' => [
                    'checkNumber' => '',
                    'checkDate' => '',
                    'paymentMethodCode' => '',
                    'paymentAmount' => 0.0,
                ],
            ],
            PaymentAdvicePage::normalizeAdvice([]),
        );
    }

    // --- ReconciliationService::computeDiscrepancy() ------------------------

    public function testComputeDiscrepancyFlagsBilledInOpenEmrButNotFoundInClaimRev(): void
    {
        $enc = $this->reconcileRow(['oeStatus' => 2, 'crFound' => false]);

        self::assertSame(
            ['description' => 'Billed in OpenEMR but not found in ClaimRev', 'level' => 'danger'],
            ReconciliationService::computeDiscrepancy($enc, false),
        );
    }

    public function testComputeDiscrepancyFlagsRejectedInClaimRevButStillBilledInOpenEmr(): void
    {
        $enc = $this->reconcileRow(['oeStatus' => 2, 'crFound' => true, 'crStatusId' => 10]);

        self::assertSame(
            ['description' => 'Rejected in ClaimRev but still Billed in OpenEMR', 'level' => 'danger'],
            ReconciliationService::computeDiscrepancy($enc, true),
        );
    }

    public function testComputeDiscrepancyReturnsEmptyWhenNothingIsWrong(): void
    {
        $enc = $this->reconcileRow([
            'oeStatus' => 2,
            'crFound' => true,
            'crStatusId' => 1,
            'crPayerAcceptanceStatusId' => 1,
            'crEraClassification' => '',
        ]);

        self::assertSame(
            ['description' => '', 'level' => ''],
            ReconciliationService::computeDiscrepancy($enc, true),
        );
    }

    /**
     * Build a fully-populated ReconcileRow shape so computeDiscrepancy() sees
     * realistic input, overriding only the fields each test cares about.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function reconcileRow(array $overrides): array
    {
        return array_merge([
            'pid' => 1,
            'encounter' => 1,
            'pcn' => '1-1',
            'encounterDate' => '2026-01-01',
            'patientName' => 'Doe, Jane',
            'patientDob' => '1980-01-01',
            'payerName' => 'Acme',
            'payerNumber' => '123',
            'totalCharges' => 100.0,
            'billTime' => '2026-01-02',
            'oeStatus' => 0,
            'oeStatusLabel' => 'Not Billed',
            'oeProcessFile' => '',
            'crFound' => false,
            'crStatusName' => '',
            'crStatusId' => 0,
            'crPayerAcceptance' => '',
            'crPayerAcceptanceStatusId' => 0,
            'crEraClassification' => '',
            'crPayerPaidAmount' => 0.0,
            'crObjectId' => '',
            'crIsWorked' => false,
            'discrepancy' => '',
            'discrepancyLevel' => '',
        ], $overrides);
    }
}
