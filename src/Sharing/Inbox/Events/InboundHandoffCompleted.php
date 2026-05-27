<?php

namespace AuthService\Helper\Sharing\Inbox\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by InboundHandoffExchangeController (D2c) AFTER auth-service has
 * validated the single-use handoff token and returned the destination
 * session credentials. Product listeners can use this for audit logging,
 * welcome banners, analytics, etc.
 */
class InboundHandoffCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $shareId,
        public readonly string $userId,
        public readonly string $targetServiceId,
        public readonly string $nextPath,
        public readonly string $consumedAt,
    ) {}
}
