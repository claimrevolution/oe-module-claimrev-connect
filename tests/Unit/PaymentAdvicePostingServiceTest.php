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
}
