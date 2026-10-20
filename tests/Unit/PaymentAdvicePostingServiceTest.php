<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use OpenEMR\Modules\ClaimRevConnector\PaymentAdvicePostingService;
use OpenEMR\Modules\ClaimRevConnector\Tests\Support\MockApiFactory;
use PHPUnit\Framework\TestCase;

final class PaymentAdvicePostingServiceTest extends TestCase
{
    public function testNotifyClaimRevWorkedPostsTheAdviceToTheToggleEndpoint(): void
    {
        $factory = MockApiFactory::withJson([]);

        (new PaymentAdvicePostingService($factory->api))
            ->notifyClaimRevWorked(['paymentAdviceId' => 'pa-1', 'paymentInfo' => ['isWorked' => false]]);

        self::assertSame('/api/PaymentAdvice/v1/UpdateClaimPaymentAdviceIsWorked', $factory->requestTarget());
        self::assertSame('pa-1', $factory->requestBody()['paymentAdviceId']);
    }

    public function testNotifyClaimRevWorkedSendsTheWholeAdvicePayload(): void
    {
        $factory = MockApiFactory::withJson([]);
        $payload = ['paymentAdviceId' => 'pa-2', 'paymentInfo' => ['isWorked' => false, 'checkNumber' => 'CHK-9']];

        (new PaymentAdvicePostingService($factory->api))->notifyClaimRevWorked($payload);

        self::assertSame('CHK-9', $factory->requestBody()['paymentInfo']['checkNumber']);
    }

    // The ClaimRev endpoint TOGGLES isWorked rather than setting it, so this
    // guard is the only thing stopping an already-worked advice from being
    // flipped back to unworked on every subsequent post. Mutation testing
    // found that inverting it produced no test failure at all, which is why
    // the decision now lives in its own predicate and is pinned here.

    public function testAlreadyWorkedAdviceIsNotNotifiedAgain(): void
    {
        self::assertFalse(PaymentAdvicePostingService::shouldNotifyClaimRevWorked([
            'paymentAdviceId' => 'pa-1',
            'paymentInfo' => ['isWorked' => true],
        ]));
    }

    public function testUnworkedAdviceIsNotified(): void
    {
        self::assertTrue(PaymentAdvicePostingService::shouldNotifyClaimRevWorked([
            'paymentAdviceId' => 'pa-1',
            'paymentInfo' => ['isWorked' => false],
        ]));
    }

    public function testAdviceWithNoWorkedFlagIsTreatedAsUnworked(): void
    {
        self::assertTrue(PaymentAdvicePostingService::shouldNotifyClaimRevWorked([
            'paymentAdviceId' => 'pa-1',
            'paymentInfo' => [],
        ]));
    }

    public function testMalformedPaymentInfoIsTreatedAsUnworked(): void
    {
        // paymentInfo absent entirely, and present but not an array.
        self::assertTrue(PaymentAdvicePostingService::shouldNotifyClaimRevWorked(['paymentAdviceId' => 'pa-1']));
        self::assertTrue(PaymentAdvicePostingService::shouldNotifyClaimRevWorked([
            'paymentAdviceId' => 'pa-1',
            'paymentInfo' => 'not-an-array',
        ]));
    }

    public function testTruthyWorkedFlagVariantsAreAllTreatedAsWorked(): void
    {
        // The API sends JSON, but a stringly-typed 1 or "true" must not be
        // read as "unworked" and cause a re-toggle.
        foreach ([true, 1, '1', 'true'] as $worked) {
            self::assertFalse(
                PaymentAdvicePostingService::shouldNotifyClaimRevWorked([
                    'paymentInfo' => ['isWorked' => $worked],
                ]),
                'isWorked=' . var_export($worked, true) . ' must count as worked',
            );
        }
    }

    // --- isAlreadyPosted(): denials ------------------------------------------

    public function testARecordedDenialCountsAsAlreadyPosted(): void
    {
        // A denial creates no ar_session, so the session checks find nothing;
        // the denial's own timeline entry must stop it being recorded again.
        \ClaimRevStubState::reset();
        \ClaimRevStubState::$queryResults = [null, 0, 1];

        $result = PaymentAdvicePostingService::isAlreadyPosted('6ad7da42f93bf10f4c289942', 9103, 629321043);

        self::assertTrue($result['posted']);
        self::assertNull($result['session_id']);
        self::assertStringContainsString('Denial', $result['details']);
        $denialQuery = end(\ClaimRevStubState::$queries);
        self::assertIsArray($denialQuery);
        self::assertSame(
            [9103, 629321043, '%' . PaymentAdvicePostingService::buildIdempotencyReference('6ad7da42f93bf10f4c289942') . '%'],
            array_slice($denialQuery['binds'], 0, 3),
        );
    }

    public function testNothingRecordedMeansNotPosted(): void
    {
        \ClaimRevStubState::reset();
        \ClaimRevStubState::$queryResults = [null, 0, 0];

        self::assertFalse(PaymentAdvicePostingService::isAlreadyPosted('abc', 9103, 629321043)['posted']);
    }

    public function testDenialTimelineDetailCarriesTheIdempotencyReference(): void
    {
        $detail = PaymentAdvicePostingService::denialDetailText('99214__CO_50,36415__CO_50', 'ClaimRev-abc');

        self::assertStringContainsString('99214__CO_50,36415__CO_50', $detail);
        self::assertStringContainsString('ClaimRev-abc', $detail);
    }

    // --- sessionPayTotal() --------------------------------------------------

    public function testSessionPayTotalIsThisClaimsPaidAmountNotTheCheckTotal(): void
    {
        // One claim off a three-claim check: the session must carry 128.64,
        // not the 385.92 check total, or every claim's session repeats the check.
        self::assertSame(128.64, PaymentAdvicePostingService::sessionPayTotal([
            ['paid' => 119.04],
            ['paid' => 9.60],
        ]));
    }

    public function testSessionPayTotalForAReversalIsNegative(): void
    {
        self::assertSame(-128.64, PaymentAdvicePostingService::sessionPayTotal([
            ['paid' => -119.04],
            ['paid' => -9.60],
        ]));
    }

    public function testSessionPayTotalIsRoundedToCents(): void
    {
        self::assertSame(0.3, PaymentAdvicePostingService::sessionPayTotal([
            ['paid' => 0.1],
            ['paid' => 0.2],
        ]));
    }

    // --- closedLevelAfterPosting() -------------------------------------------

    public function testAPaymentClosesItsInsuranceLevel(): void
    {
        self::assertSame(1, PaymentAdvicePostingService::closedLevelAfterPosting('1', 1, 1, 0));
        self::assertSame(2, PaymentAdvicePostingService::closedLevelAfterPosting('2', 2, 1, 0));
    }

    public function testAReversalWithNothingLeftStandingReopensTheLevel(): void
    {
        // TESTREVERSAL: one payment, then its reversal. The payer owes the
        // claim again, so the balance must not fall to the patient.
        self::assertSame(0, PaymentAdvicePostingService::closedLevelAfterPosting('22', 1, 1, 1));
    }

    public function testAReversalPostedAfterItsCorrectionKeepsTheLevelClosed(): void
    {
        // TESTCORRECTION, repayment posted before the reversal: two payments,
        // one reversal, so a payment still stands.
        self::assertSame(1, PaymentAdvicePostingService::closedLevelAfterPosting('22', 1, 2, 1));
    }

    public function testAReversalOnSecondaryReopensOnlyTheSecondaryLevel(): void
    {
        self::assertSame(1, PaymentAdvicePostingService::closedLevelAfterPosting('22', 2, 1, 1));
    }
}
