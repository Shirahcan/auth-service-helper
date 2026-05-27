<?php

namespace AuthService\Helper\Sharing\Envelope;

use AuthService\Helper\Sharing\Envelope\Contracts\IdempotencyStore;

final class IdempotencyGuard
{
    public function __construct(private readonly IdempotencyStore $store) {}

    public function hasSeen(string $sourceServiceId, string $idempotencyKey): bool
    {
        return $this->store->exists($sourceServiceId, $idempotencyKey);
    }

    public function record(string $sourceServiceId, string $idempotencyKey, string $messageId): void
    {
        $this->store->record($sourceServiceId, $idempotencyKey, $messageId);
    }
}
