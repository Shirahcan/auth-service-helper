# Phase D3 — `InboundShareReceived` + `InboundHandoffCompleted` events

**Repo:** `auth-service-helper`
**Spec section:** §7 "Destination-Side API" (Event handler example) + §9 destination lifecycle
**Depends on:** B6 (`SharePayload` contract)

## Goal

Two Laravel events that product code listens to. `InboundShareReceived` carries the typed payload that the webhook controller (D2c) hydrated via the `IntentRegistry`. `InboundHandoffCompleted` fires when the destination's `/auth/handoff` route successfully exchanged a token. Both extend `Dispatchable` + `SerializesModels` so individual listeners can opt into `ShouldQueue`.

## Files

- **Create:** `src/Sharing/Inbox/Events/InboundShareReceived.php`
- **Create:** `src/Sharing/Inbox/Events/InboundHandoffCompleted.php`
- **Test:** `tests/Unit/Sharing/Inbox/InboundShareReceivedTest.php`

## Steps

### Step 1 — Failing event test

```php
<?php
// tests/Unit/Sharing/Inbox/InboundShareReceivedTest.php

namespace Tests\Unit\Sharing\Inbox;

use AuthService\Helper\Sharing\Inbox\Events\InboundHandoffCompleted;
use AuthService\Helper\Sharing\Inbox\Events\InboundShareReceived;
use AuthService\Helper\Sharing\Intents\Contracts\SharePayload;
use Orchestra\Testbench\TestCase;

class InboundShareReceivedTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\AuthService\Helper\AuthServiceHelperServiceProvider::class];
    }

    public function test_event_exposes_typed_payload_and_envelope_metadata(): void
    {
        $payload = new FakeInboundPayload('ord_x');

        $event = new InboundShareReceived(
            shareId: 'share_uuid',
            intent: 'test.intent',
            intentVersion: '1.0',
            userId: 'user_uuid',
            sourceServiceId: 'src_uuid',
            payload: $payload,
            messageId: 'msg_uuid',
            correlationId: 'share_uuid',
            metadata: ['received_at' => '2026-05-27T10:00:00Z'],
        );

        $this->assertEquals('share_uuid', $event->shareId);
        $this->assertEquals('test.intent', $event->intent);
        $this->assertEquals('1.0', $event->intentVersion);
        $this->assertEquals('user_uuid', $event->userId);
        $this->assertEquals('src_uuid', $event->sourceServiceId);
        $this->assertSame($payload, $event->payload);
        $this->assertEquals('msg_uuid', $event->messageId);
        $this->assertEquals('share_uuid', $event->correlationId);
        $this->assertEquals(['received_at' => '2026-05-27T10:00:00Z'], $event->metadata);
    }

    public function test_handoff_completed_event_exposes_fields(): void
    {
        $event = new InboundHandoffCompleted(
            shareId: 'share_uuid',
            userId: 'user_uuid',
            targetServiceId: 'tgt_uuid',
            nextPath: '/cases/123',
            consumedAt: '2026-05-27T10:00:01Z',
        );

        $this->assertEquals('share_uuid', $event->shareId);
        $this->assertEquals('user_uuid', $event->userId);
        $this->assertEquals('tgt_uuid', $event->targetServiceId);
        $this->assertEquals('/cases/123', $event->nextPath);
        $this->assertEquals('2026-05-27T10:00:01Z', $event->consumedAt);
    }

    public function test_share_received_uses_dispatchable_and_serializes_models(): void
    {
        $traits = class_uses(InboundShareReceived::class);
        $this->assertContains(\Illuminate\Foundation\Events\Dispatchable::class, $traits);
        $this->assertContains(\Illuminate\Queue\SerializesModels::class, $traits);
    }

    public function test_handoff_completed_uses_dispatchable_and_serializes_models(): void
    {
        $traits = class_uses(InboundHandoffCompleted::class);
        $this->assertContains(\Illuminate\Foundation\Events\Dispatchable::class, $traits);
        $this->assertContains(\Illuminate\Queue\SerializesModels::class, $traits);
    }
}

class FakeInboundPayload implements SharePayload
{
    public function __construct(public readonly string $orderId) {}
    public static function fromArray(array $raw): self { return new self($raw['order_id']); }
    public function toArray(): array { return ['order_id' => $this->orderId]; }
    public static function intentSlug(): string { return 'test.intent'; }
    public static function intentVersion(): string { return '1.0'; }
}
```

### Step 2 — Run, expect failure

```bash
vendor/bin/pest tests/Unit/Sharing/Inbox/InboundShareReceivedTest.php
```

Expected: event classes not found.

### Step 3 — Implement the events

```php
<?php
// src/Sharing/Inbox/Events/InboundShareReceived.php

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
```

```php
<?php
// src/Sharing/Inbox/Events/InboundHandoffCompleted.php

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
```

### Step 4 — Run + commit

```bash
vendor/bin/pest tests/Unit/Sharing/Inbox/InboundShareReceivedTest.php
# Expected: 4 passed.

git add src/Sharing/Inbox/Events/ \
        tests/Unit/Sharing/Inbox/InboundShareReceivedTest.php
git commit -m "feat(sharing): phase D3 — InboundShareReceived + InboundHandoffCompleted events

Two Laravel events product code listens to:
 - InboundShareReceived carries the typed SharePayload (hydrated by the
   IntentRegistry in D2c), plus envelope metadata (message_id,
   correlation_id, source_service_id, etc.).
 - InboundHandoffCompleted fires on successful token exchange via the
   destination's /auth/handoff route.

Both use Dispatchable + SerializesModels so individual listeners may opt
into ShouldQueue without changing the dispatch site.

Phase: D3 of docs/plans/InterProductCommunication-2026-05-27/"
```

## Verification checklist

- [ ] Both events have only public readonly properties (immutable payload shapes)
- [ ] Both use `Dispatchable` + `SerializesModels` traits
- [ ] `payload` on `InboundShareReceived` is typed against the `SharePayload` interface (not array)
- [ ] No constructor logic — they are pure value objects, so queued listeners can serialize cleanly
