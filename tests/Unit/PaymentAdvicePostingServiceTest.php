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
}
