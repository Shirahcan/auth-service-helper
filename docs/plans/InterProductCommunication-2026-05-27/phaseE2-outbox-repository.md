# Phase E2 — `SharingOutboxRepository`

**Repo:** `auth-service-helper`
**Spec section:** §7 "Source-Side API" + §9 "Source Side"
**Depends on:** E1 (OutboundShareMessage), C4 (SharingService — for wiring `sendPayload`)

## Goal

A single repository that owns ALL state transitions on `outbound_share_messages`. The job (E3+) calls it; the facade (C4) calls `enqueue()` from `Sharing::sendPayload()`; status surfaces (E6) read through it. Centralising mutations keeps the state machine auditable and the job logic thin.

## Files

- **Create:** `src/Sharing/Outbox/SharingOutboxRepository.php`
- **Modify:** `src/Sharing/SharingService.php` (wire `sendPayload()` to the repository + dispatch job)
- **Test:** `tests/Unit/Sharing/Outbox/SharingOutboxRepositoryTest.php`

## Steps

### Step 1 — Failing repository test

```php
<?php
// tests/Unit/Sharing/Outbox/SharingOutboxRepositoryTest.php

namespace Tests\Unit\Sharing\Outbox;

use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use AuthService\Helper\Sharing\Outbox\OutboundShareMessage;
use AuthService\Helper\Sharing\Outbox\SharingOutboxRepository;
use AuthService\Helper\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

class SharingOutboxRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_enqueue_inserts_queued_row(): void
    {
        $repo = new SharingOutboxRepository();
        $env = $this->envelope();

        $row = $repo->enqueue($env, peerSlug: 'portify');

        $this->assertEquals(OutboundShareMessage::STATUS_QUEUED, $row->status);
        $this->assertEquals('portify', $row->peer_slug);
        $this->assertEquals($env->idempotencyKey, $row->idempotency_key);
        $this->assertEquals($env->intent, $row->intent);
        $this->assertEquals(0, $row->attempts);
    }

    public function test_enqueue_is_idempotent_by_peer_and_key(): void
    {
        $repo = new SharingOutboxRepository();
        $env = $this->envelope();

        $first = $repo->enqueue($env, peerSlug: 'portify');
        $second = $repo->enqueue($env, peerSlug: 'portify');

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, OutboundShareMessage::query()->count());
    }

    public function test_find_deliverable_returns_due_rows_only(): void
    {
        $repo = new SharingOutboxRepository();
        $due = OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_RETRY_SCHEDULED, 'next_retry_at' => Carbon::now()->subMinute()]);
        OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_RETRY_SCHEDULED, 'next_retry_at' => Carbon::now()->addHour()]);
        OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_DELIVERED]);
        $queued = OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_QUEUED, 'next_retry_at' => null]);

        $ids = $repo->findDeliverable(limit: 25)->pluck('id')->all();
        $this->assertContains($due->id, $ids);
        $this->assertContains($queued->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_mark_in_flight_bumps_attempts(): void
    {
        $repo = new SharingOutboxRepository();
        $row = OutboundShareMessage::factory()->create(['attempts' => 1]);

        $repo->markInFlight($row);

        $row->refresh();
        $this->assertEquals(OutboundShareMessage::STATUS_IN_FLIGHT, $row->status);
        $this->assertEquals(2, $row->attempts);
        $this->assertNotNull($row->last_attempt_at);
    }

    public function test_mark_delivered_sets_terminal_state(): void
    {
        $repo = new SharingOutboxRepository();
        $row = OutboundShareMessage::factory()->create([
            'status' => OutboundShareMessage::STATUS_IN_FLIGHT,
        ]);

        $repo->markDelivered($row);

        $row->refresh();
        $this->assertEquals(OutboundShareMessage::STATUS_DELIVERED, $row->status);
        $this->assertNotNull($row->delivered_at);
    }

    public function test_schedule_retry_writes_next_retry_at(): void
    {
        $repo = new SharingOutboxRepository();
        $row = OutboundShareMessage::factory()->create([
            'status' => OutboundShareMessage::STATUS_IN_FLIGHT,
            'attempts' => 1,
        ]);

        $repo->scheduleRetry($row, attemptN: 1, responseStatus: 503, error: 'upstream 503');
        $row->refresh();

        $this->assertEquals(OutboundShareMessage::STATUS_RETRY_SCHEDULED, $row->status);
        $this->assertNotNull($row->next_retry_at);
        $this->assertEquals(503, $row->last_response_status);
        $this->assertSame('upstream 503', $row->last_error);
    }

    public function test_dead_letter_terminal(): void
    {
        $repo = new SharingOutboxRepository();
        $row = OutboundShareMessage::factory()->create([
            'status' => OutboundShareMessage::STATUS_IN_FLIGHT,
        ]);

        $repo->deadLetter($row, error: 'attempts exhausted');
        $row->refresh();

        $this->assertEquals(OutboundShareMessage::STATUS_DEAD_LETTERED, $row->status);
        $this->assertNotNull($row->dead_lettered_at);
        $this->assertSame('attempts exhausted', $row->last_error);
    }

    public function test_get_by_share_id_returns_collection(): void
    {
        $shareId = 'share-1';
        OutboundShareMessage::factory()->count(3)->create(['share_id' => $shareId]);
        OutboundShareMessage::factory()->create(['share_id' => 'share-2']);

        $repo = new SharingOutboxRepository();
        $this->assertCount(3, $repo->getByShareId($shareId));
    }

    public function test_get_by_message_id_returns_single(): void
    {
        $row = OutboundShareMessage::factory()->create();
        $repo = new SharingOutboxRepository();

        $this->assertEquals($row->id, $repo->getByMessageId($row->id)->id);
    }

    private function envelope(): ShareEnvelope
    {
        return ShareEnvelope::fromArray([
            'envelope_version' => '1',
            'message_id' => 'msg_01HZ',
            'correlation_id' => 'share_01',
            'intent' => 'service_purchase',
            'intent_version' => '1.0',
            'source_service_id' => '00000000-0000-0000-0000-000000000001',
            'target_service_id' => '00000000-0000-0000-0000-000000000002',
            'user_id' => '00000000-0000-0000-0000-000000000003',
            'idempotency_key' => 'studendly:order:88421',
            'issued_at' => '2026-05-27T10:00:00Z',
            'payload' => ['order_id' => 'x'],
        ]);
    }
}
```

### Step 2 — Run, expect failure

```bash
vendor/bin/pest tests/Unit/Sharing/Outbox/SharingOutboxRepositoryTest.php
```

### Step 3 — Implement the repository

```php
<?php
// src/Sharing/Outbox/SharingOutboxRepository.php

namespace AuthService\Helper\Sharing\Outbox;

use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SharingOutboxRepository
{
    /**
     * Insert (or return existing) outbound message for a given envelope.
     * Idempotent on (peer_slug, idempotency_key).
     */
    public function enqueue(ShareEnvelope $envelope, string $peerSlug): OutboundShareMessage
    {
        $existing = OutboundShareMessage::query()
            ->where('peer_slug', $peerSlug)
            ->where('idempotency_key', $envelope->idempotencyKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        return OutboundShareMessage::create([
            'id' => (string) Str::uuid(),
            'share_id' => $envelope->correlationId,
            'target_service_id' => $envelope->targetServiceId,
            'peer_slug' => $peerSlug,
            'intent' => $envelope->intent,
            'intent_version' => $envelope->intentVersion,
            'idempotency_key' => $envelope->idempotencyKey,
            'envelope_json' => $envelope->toArray(),
            'signature_header' => null,
            'status' => OutboundShareMessage::STATUS_QUEUED,
            'attempts' => 0,
            'next_retry_at' => null,
        ]);
    }

    /**
     * Rows due for delivery: queued (no schedule) OR retry_scheduled with
     * next_retry_at <= now.
     */
    public function findDeliverable(int $limit = 25): Collection
    {
        return OutboundShareMessage::query()
            ->whereIn('status', OutboundShareMessage::DELIVERABLE_STATES)
            ->where(function ($q) {
                $q->whereNull('next_retry_at')
                  ->orWhere('next_retry_at', '<=', Carbon::now());
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->get();
    }

    public function markInFlight(OutboundShareMessage $row): void
    {
        $row->forceFill([
            'status' => OutboundShareMessage::STATUS_IN_FLIGHT,
            'attempts' => $row->attempts + 1,
            'last_attempt_at' => Carbon::now(),
        ])->save();
    }

    public function markDelivered(OutboundShareMessage $row): void
    {
        $row->forceFill([
            'status' => OutboundShareMessage::STATUS_DELIVERED,
            'delivered_at' => Carbon::now(),
            'next_retry_at' => null,
        ])->save();
    }

    public function scheduleRetry(
        OutboundShareMessage $row,
        int $attemptN,
        ?int $responseStatus,
        ?string $error,
    ): void {
        // Default 1m delay; E4 replaces the schedule with exponential backoff
        $delaySeconds = 60;

        $row->forceFill([
            'status' => OutboundShareMessage::STATUS_RETRY_SCHEDULED,
            'next_retry_at' => Carbon::now()->addSeconds($delaySeconds),
            'last_response_status' => $responseStatus,
            'last_error' => $error,
        ])->save();
    }

    public function deadLetter(OutboundShareMessage $row, ?string $error = null): void
    {
        $row->forceFill([
            'status' => OutboundShareMessage::STATUS_DEAD_LETTERED,
            'dead_lettered_at' => Carbon::now(),
            'next_retry_at' => null,
            'last_error' => $error ?? $row->last_error,
        ])->save();
    }

    public function markFailedPermanent(
        OutboundShareMessage $row,
        ?int $responseStatus,
        ?string $error,
    ): void {
        $row->forceFill([
            'status' => OutboundShareMessage::STATUS_FAILED_PERMANENT,
            'last_response_status' => $responseStatus,
            'last_error' => $error,
            'next_retry_at' => null,
        ])->save();
    }

    public function getByShareId(string $shareId): Collection
    {
        return OutboundShareMessage::query()->where('share_id', $shareId)->get();
    }

    public function getByMessageId(string $messageId): ?OutboundShareMessage
    {
        return OutboundShareMessage::query()->find($messageId);
    }
}
```

### Step 4 — Wire `Sharing::sendPayload()` through the repository

Edit `src/Sharing/SharingService.php` — replace the stub `sendPayload()` body from C4 with:

```php
use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use AuthService\Helper\Sharing\Outbox\OutboundShareMessage;
use AuthService\Helper\Sharing\Outbox\SharingOutboxRepository;
use AuthService\Helper\Sharing\Outbox\Jobs\DispatchOutboundShareJob;
use AuthService\Helper\Sharing\Intents\Contracts\SharePayload;
use Illuminate\Support\Str;

public function __construct(
    // …existing C4 deps…
    protected SharingOutboxRepository $outbox,
) {}

public function sendPayload(
    string $shareId,
    string $intent,
    SharePayload $payload,
    string $idempotencyKey,
    ?string $peerSlug = null,
    ?string $targetServiceId = null,
    ?string $userId = null,
    ?string $sourceServiceId = null,
): OutboundShareMessage {
    $slug = $peerSlug ?? (string) config('authservice.sharing.default_peer_slug', 'portify');

    $envelope = ShareEnvelope::fromArray([
        'envelope_version' => '1',
        'message_id' => 'msg_'.Str::ulid()->toBase32(),
        'correlation_id' => $shareId,
        'intent' => $intent,
        'intent_version' => $payload::intentVersion(),
        'source_service_id' => $sourceServiceId
            ?? (string) config('authservice.sharing.source_service_id'),
        'target_service_id' => $targetServiceId
            ?? (string) config("authservice.sharing.peers.{$slug}.target_service_id"),
        'user_id' => $userId ?? '',
        'idempotency_key' => $idempotencyKey,
        'issued_at' => now()->toIso8601String(),
        'payload' => $payload->toArray(),
    ]);

    $row = $this->outbox->enqueue($envelope, $slug);

    if ($row->status === OutboundShareMessage::STATUS_QUEUED) {
        DispatchOutboundShareJob::dispatch($row->id);
    }

    return $row;
}
```

Note: `DispatchOutboundShareJob` is created in E3. The class reference compiles before E3 ships but won't dispatch successfully — that's fine; E2 only proves the repository contract.

### Step 4a — ALSO update the SharingServiceProvider binding closure

The C4 phase registered `SharingService` as a singleton with TWO constructor args (`UserShareClient`, `HandoffTokenClient`). E2 adds a third (`SharingOutboxRepository`). You MUST update the binding closure in the same edit, or the first resolution will throw "Too few arguments" at boot:

```php
// src/Sharing/SharingServiceProvider.php  ::register()
$this->app->singleton(\AuthService\Helper\Sharing\SharingService::class, function ($app) {
    return new \AuthService\Helper\Sharing\SharingService(
        $app->make(\AuthService\Helper\Sharing\Client\UserShareClient::class),
        $app->make(\AuthService\Helper\Sharing\Client\HandoffTokenClient::class),
        $app->make(\AuthService\Helper\Sharing\Outbox\SharingOutboxRepository::class), // NEW in E2
    );
});
```

Run a single resolution test to prove the binding works after this edit:

```bash
vendor/bin/pest tests/Unit/Sharing/Outbox/SharingOutboxRepositoryTest.php tests/Unit/Sharing/SharingFacadeTest.php
# Expected: both test files green.
```

### Step 5 — Run + commit

```bash
vendor/bin/pest tests/Unit/Sharing/Outbox/SharingOutboxRepositoryTest.php
# Expected: 9 passed.

git add src/Sharing/Outbox/SharingOutboxRepository.php \
        src/Sharing/SharingService.php \
        tests/Unit/Sharing/Outbox/SharingOutboxRepositoryTest.php
git commit -m "feat(sharing): phase E2 — SharingOutboxRepository

Centralises all outbound state transitions: enqueue (idempotent by
peer+idempotency_key), findDeliverable, markInFlight, markDelivered,
scheduleRetry (1m stub — E4 swaps for real backoff), deadLetter,
markFailedPermanent. Wired into Sharing::sendPayload (from C4).

Phase: E2 of docs/plans/InterProductCommunication-2026-05-27/"
```

## Verification checklist

- [ ] enqueue is idempotent (re-call returns same row)
- [ ] findDeliverable excludes future retries and terminal states
- [ ] Each transition method writes the expected columns
- [ ] Sharing::sendPayload dispatches the job ONLY for newly-queued rows
