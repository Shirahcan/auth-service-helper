<?php

namespace AuthService\Helper\Sharing\Envelope\Contracts;

interface IdempotencyStore
{
    public function exists(string $sourceServiceId, string $idempotencyKey): bool;
    public function record(string $sourceServiceId, string $idempotencyKey, string $messageId): void;
}
