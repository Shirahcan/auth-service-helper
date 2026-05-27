<?php

namespace AuthService\Helper\Sharing\Inbox\Queries;

use AuthService\Helper\Sharing\Inbox\InboundShareMessage;

class Sharing
{
    public static function lastInboundFor(string $shareId): ?InboundShareMessage
    {
        return InboundShareMessage::query()
            ->where('correlation_id', $shareId)
            ->orderByDesc('received_at')
            ->first();
    }
}
