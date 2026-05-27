<?php

namespace Tests\Integration\Sharing\Fixtures;

use AuthService\Helper\Sharing\Inbox\Events\InboundShareReceived;

final class TestServicePurchaseHandler
{
    /** @var array<int,InboundShareReceived> */
    public static array $received = [];

    public function handle(InboundShareReceived $event): void
    {
        if ($event->intent !== 'service_purchase') {
            return;
        }
        self::$received[] = $event;
    }

    public static function reset(): void
    {
        self::$received = [];
    }
}
