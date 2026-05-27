<?php

namespace Tests\Unit\Sharing\Envelope;

use AuthService\Helper\Sharing\Envelope\Contracts\IdempotencyStore;
use AuthService\Helper\Sharing\Envelope\IdempotencyGuard;
use PHPUnit\Framework\TestCase;

class IdempotencyGuardTest extends TestCase
{
    public function test_first_seen_returns_false_then_records(): void
    {
        $guard = new IdempotencyGuard(new InMemoryStore());

        $this->assertFalse($guard->hasSeen('source-uuid', 'k1'));
        $guard->record('source-uuid', 'k1', 'msg-1');
        $this->assertTrue($guard->hasSeen('source-uuid', 'k1'));
    }

    public function test_different_source_with_same_key_is_independent(): void
    {
        $guard = new IdempotencyGuard(new InMemoryStore());

        $guard->record('source-A', 'k1', 'msg-1');
        $this->assertTrue($guard->hasSeen('source-A', 'k1'));
        $this->assertFalse($guard->hasSeen('source-B', 'k1'));
    }
}

class InMemoryStore implements IdempotencyStore
{
    private array $store = [];

    public function exists(string $sourceServiceId, string $idempotencyKey): bool
    {
        return isset($this->store[$sourceServiceId.':'.$idempotencyKey]);
    }

    public function record(string $sourceServiceId, string $idempotencyKey, string $messageId): void
    {
        $this->store[$sourceServiceId.':'.$idempotencyKey] = $messageId;
    }
}
