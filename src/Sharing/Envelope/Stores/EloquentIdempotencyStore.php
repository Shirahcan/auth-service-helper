<?php

namespace AuthService\Helper\Sharing\Envelope\Stores;

use AuthService\Helper\Sharing\Envelope\Contracts\IdempotencyStore;
use AuthService\Helper\Sharing\Inbox\InboundShareMessage;

class EloquentIdempotencyStore implements IdempotencyStore
{
    public function exists(string $sourceServiceId, string $idempotencyKey): bool
    {
        return InboundShareMessage::query()
            ->where('source_service_id', $sourceServiceId)
            ->where('idempotency_key', $idempotencyKey)
            ->exists();
    }

    /**
     * No-op: the row is inserted by the controller (UNIQUE constraint is the
     * real guard). This method exists only to satisfy the contract for
     * non-DB-backed test stores.
     */
    public function record(string $sourceServiceId, string $idempotencyKey, string $messageId): void
    {
        // Intentionally empty — see class docblock.
    }
}
