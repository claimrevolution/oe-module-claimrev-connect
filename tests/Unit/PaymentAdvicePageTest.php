<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClaimRevConnector\ClaimRevApiException;
use OpenEMR\Modules\ClaimRevConnector\PaymentAdvicePage;
use OpenEMR\Modules\ClaimRevConnector\Tests\Support\MockApiFactory;
use PHPUnit\Framework\TestCase;

final class PaymentAdvicePageTest extends TestCase
{
    public function testFetchPaymentInfoPostsToTheSearchEndpoint(): void
    {
        $factory = MockApiFactory::withJson(['results' => [], 'totalRecords' => 0]);

        $result = (new PaymentAdvicePage($factory->api))->fetchPaymentInfo(['checkNumber' => 'CHK-1']);

        self::assertSame('/api/PaymentAdvice/v1/SearchPaymentInfo', $factory->requestTarget());
        self::assertSame('CHK-1', $factory->requestBody()['checkNumber']);
        self::assertSame(0, $result['totalRecords']);
    }

    public function testFetchPaymentAdviceByIdReturnsTheMatchingEntry(): void
    {
        $factory = MockApiFactory::withJson([
            'results' => [['paymentAdviceId' => 'pa-1', 'paymentInfo' => []]],
            'totalRecords' => 1,
        ]);

        $result = (new PaymentAdvicePage($factory->api))->fetchPaymentAdviceById('pa-1');

        self::assertNotNull($result);
        self::assertSame('pa-1', $result['paymentAdviceId']);
    }

    public function testFetchPaymentAdviceByIdRejectsAMismatchedRow(): void
    {
        // Defensive re-check: the server filter is authoritative, but a bug
        // there must never leak a different advice through.
        $factory = MockApiFactory::withJson([
            'results' => [['paymentAdviceId' => 'SOMETHING-ELSE', 'paymentInfo' => []]],
            'totalRecords' => 1,
        ]);

        self::assertNull((new PaymentAdvicePage($factory->api))->fetchPaymentAdviceById('pa-1'));
    }

    public function testFetchPaymentAdviceByIdShortCircuitsOnAnEmptyId(): void
    {
        $factory = MockApiFactory::withJson(['results' => [], 'totalRecords' => 0]);

        self::assertNull((new PaymentAdvicePage($factory->api))->fetchPaymentAdviceById(''));
        self::assertSame(0, $factory->requestCount(), 'An empty id must not reach the API');
    }

    public function testInstanceMethodsLetApiFailuresPropagate(): void
    {
        $factory = new MockApiFactory([new Response(500, [], 'boom')]);

        $this->expectException(ClaimRevApiException::class);

        (new PaymentAdvicePage($factory->api))->fetchPaymentInfo([]);
    }
}
