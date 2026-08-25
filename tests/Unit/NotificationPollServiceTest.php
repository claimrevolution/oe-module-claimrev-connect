<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use ClaimRevStubState;
use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClaimRevConnector\NotificationPollService;
use OpenEMR\Modules\ClaimRevConnector\Tests\Support\MockApiFactory;
use PHPUnit\Framework\TestCase;

final class NotificationPollServiceTest extends TestCase
{
    protected function setUp(): void
    {
        ClaimRevStubState::reset();
        ClaimRevStubState::$globals['oe_claimrev_notification_recipient'] = 'admin';
    }

    protected function tearDown(): void
    {
        ClaimRevStubState::reset();
    }

    public function testPollNotificationsRequestsUnreadNotifications(): void
    {
        $factory = MockApiFactory::withJson([]);

        (new NotificationPollService($factory->api))->pollNotifications(['admin']);

        self::assertStringContainsString('/api/NotificationMgmt/v1/GetPortalNotifications', $factory->requestTarget());
    }

    public function testPollNotificationsCreatesAPnoteForEachRecipient(): void
    {
        // Two requests happen for an undelivered notification: the initial
        // GetPortalNotifications fetch, then SetNotificationReadStatus after
        // delivery. MockApiFactory::withJson() only queues one response, so
        // build the queue directly here.
        $factory = new MockApiFactory([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                ['portalNotificationId' => 11, 'messageTitle' => 'Payer outage', 'messageBodyText' => 'Details here'],
            ], JSON_THROW_ON_ERROR)),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([], JSON_THROW_ON_ERROR)),
        ]);
        // querySingleRow returns false → not previously delivered.
        ClaimRevStubState::$queryResults = [false];

        (new NotificationPollService($factory->api))->pollNotifications(['admin', 'biller1']);

        self::assertCount(2, ClaimRevStubState::$pnotes);
        self::assertSame('admin', ClaimRevStubState::$pnotes[0]['assigned_to']);
        self::assertSame('biller1', ClaimRevStubState::$pnotes[1]['assigned_to']);
        self::assertStringContainsString('Payer outage', ClaimRevStubState::$pnotes[0]['text']);
    }

    public function testPollNotificationsSkipsAlreadyDeliveredNotifications(): void
    {
        $factory = MockApiFactory::withJson([
            ['portalNotificationId' => 11, 'messageTitle' => 'Seen already', 'messageBodyText' => 'x'],
        ]);
        // querySingleRow returns a row → already delivered.
        ClaimRevStubState::$queryResults = [['id' => 5]];

        (new NotificationPollService($factory->api))->pollNotifications(['admin']);

        self::assertSame([], ClaimRevStubState::$pnotes);
    }
}
