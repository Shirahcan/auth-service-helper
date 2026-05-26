# Phase B5 — `IdempotencyGuard`

**Repo:** `auth-service-helper`
**Spec section:** §10 "Idempotency Model"
**Depends on:** B1

## Goal

Look up whether `(source_service_id, idempotency_key)` has been seen recently (30-day retention), and mark it if not. Backed by `inbound_share_messages` (table created in Phase D1). For now we define the contract; the storage adapter is wired in D2b.

## Files

- **Create:** `src/Sharing/Envelope/IdempotencyGuard.php`
- **Create:** `src/Sharing/Envelope/Contracts/IdempotencyStore.php`
- **Test:** `tests/Unit/Sharing/Envelope/IdempotencyGuardTest.php`

## Steps

### Step 1 — Failing test (in-memory fake store)

```php
<?php
// tests/Unit/Sharing/Envelope/IdempotencyGuardTest.php

namespace Tests\Unit\Sharing\Envelope;

use AuthService\Helper\Sharing\Envelope\Contracts\IdempotencyStore;
use AuthService\Helper\Sharing\Envelope\IdempotencyGuard;
use PHPUnit\Framework\TestCase;

class IdempotencyGuardTest extends TestCase
{
    public function test_first_seen_returns_false_then_records(): void
    {
        $store = new InMemoryStore();
        $guard = new IdempotencyGuard($store);

        $this->assertFalse($guard->hasSeen('source-uuid', 'k1'));
        $guard->record('source-uuid', 'k1', messageId: 'msg-1');
        $this->assertTrue($guard->hasSeen('source-uuid', 'k1'));
    }

    public function test_different_source_with_same_key_is_independent(): void
    {
        $store = new InMemoryStore();
        $guard = new IdempotencyGuard($store);

        $guard->record('source-A', 'k1', messageId: 'msg-1');
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
```

### Step 2 — Run, expect failure

```bash
vendor/bin/pest tests/Unit/Sharing/Envelope/IdempotencyGuardTest.php
```

### Step 3 — Implement contract + guard

```php
<?php
// src/Sharing/Envelope/Contracts/IdempotencyStore.php

namespace AuthService\Helper\Sharing\Envelope\Contracts;

interface IdempotencyStore
{
    public function exists(string $sourceServiceId, string $idempotencyKey): bool;
    public function record(string $sourceServiceId, string $idempotencyKey, string $messageId): void;
}
```

```php
<?php
// src/Sharing/Envelope/IdempotencyGuard.php

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
```

### Step 4 — Run + commit

```bash
vendor/bin/pest tests/Unit/Sharing/Envelope/IdempotencyGuardTest.php
# Expected: 2 passed.

git add src/Sharing/Envelope/ tests/Unit/Sharing/Envelope/IdempotencyGuardTest.php
git commit -m "feat(sharing): phase B5 — IdempotencyGuard contract

Defines IdempotencyStore interface + thin guard. The DB-backed
implementation against inbound_share_messages is wired in D2b. Keeps
the guard testable against an in-memory fake.

Phase: B5 of docs/plans/InterProductCommunication-2026-05-27/"
```
