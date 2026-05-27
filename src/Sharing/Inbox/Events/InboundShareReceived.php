<?php

namespace AuthService\Helper\Sharing\Inbox\Events;

use AuthService\Helper\Sharing\Intents\Contracts\SharePayload;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired AFTER the inbound webhook controller (D2c) has persisted the row,
 * validated the payload against the intent's JSON-schema, and hydrated it
 * via IntentRegistry. Product listeners get a fully-typed payload.
 *
 * Synchronous by default; individual listeners may opt into ShouldQueue.
 */
class InboundShareReceived
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $metadata  Envelope-level metadata (received_at, headers, etc.)
     */
    public function __construct(
        public readonly string $shareId,
        public readonly string $intent,
        public readonly string $intentVersion,
        public readonly string $userId,
        public readonly string $sourceServiceId,
        public readonly SharePayload $payload,
        public readonly string $messageId,
        public readonly string $correlationId,
        public readonly array $metadata = [],
    ) {}
}
