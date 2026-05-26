# Phase E5 — Dead-letter handling + `Sharing::redeliver`

**Repo:** `auth-service-helper`
**Spec section:** §9 "Source Side" + §13 "Edge Cases"
**Depends on:** E4 (retry logic), C4 (Sharing facade)

## Goal

DLQ recovery surface. An operator (or automation) can re-attempt delivery of a `dead_lettered` row by calling `Sharing::redeliver($outboundMessageId)` — the row's attempt counter resets so it gets the full 5-attempt backoff schedule again, status flips to `queued`, and a fresh `DispatchOutboundShareJob` is dispatched.

`Sharing::listFailed()` returns BOTH `dead_lettered` and `failed_permanent` rows so operators can triage in one query.

Re-delivering a `delivered` or `failed_permanent` row throws `RedeliveryNotPermittedException` — both are intentional terminal states (delivered = nothing to do; failed_permanent = destination rejected and would reject again).

## Files

- **Create:** `src/Sharing/Outbox/Exceptions/RedeliveryNotPermittedException.php`
- **Modify:** `src/Sharing/SharingService.php` (add `redeliver()` + `listFailed()`)
- **Test:** `tests/Unit/Sharing/Outbox/RedeliveryTest.php`

## Steps

### Step 1 — Failing test

```php
<?php
// tests/Unit/Sharing/Outbox/RedeliveryTest.php

namespace Tests\Unit\Sharing\Outbox;

use AuthService\Helper\Sharing\Outbox\Exceptions\RedeliveryNotPermittedException;
use AuthService\Helper\Sharing\Outbox\Jobs\DispatchOutboundShareJob;
use AuthService\Helper\Sharing\Outbox\OutboundShareMessage;
use AuthService\Helper\Sharing\SharingService;
use AuthService\Helper\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

class RedeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_redeliver_dead_lettered_row_resets_state_and_dispatches_job(): void
    {
        Queue::fake();

        $row = OutboundShareMessage::factory()->create([
            'status' => OutboundShareMessage::STATUS_DEAD_LETTERED,
            'attempts' => 6,
            'dead_lettered_at' => now()->subHour(),
            'last_error' => 'previous failure',
        ]);

        $sharing = $this->app->make(SharingService::class);
        $sharing->redeliver($row->id);

        $row->refresh();
        $this->assertEquals(OutboundShareMessage::STATUS_QUEUED, $row->status);
        $this->assertEquals(0, $row->attempts);
        $this->assertNull($row->dead_lettered_at);
        $this->assertNull($row->next_retry_at);

        Queue::assertPushed(
            DispatchOutboundShareJob::class,
            fn ($job) => $job->outboundMessageId === $row->id,
        );
    }

    public function test_redeliver_delivered_row_throws(): void
    {
        $row = OutboundShareMessage::factory()->create([
            'status' => OutboundShareMessage::STATUS_DELIVERED,
        ]);

        $sharing = $this->app->make(SharingService::class);
        $this->expectException(RedeliveryNotPermittedException::class);
        $sharing->redeliver($row->id);
    }

    public function test_redeliver_failed_permanent_row_throws(): void
    {
        $row = OutboundShareMessage::factory()->create([
            'status' => OutboundShareMessage::STATUS_FAILED_PERMANENT,
        ]);

        $sharing = $this->app->make(SharingService::class);
        $this->expectException(RedeliveryNotPermittedException::class);
        $sharing->redeliver($row->id);
    }

    public function test_redeliver_queued_row_throws_already_pending(): void
    {
        $row = OutboundShareMessage::factory()->create([
            'status' => OutboundShareMessage::STATUS_QUEUED,
        ]);

        $sharing = $this->app->make(SharingService::class);
        $this->expectException(RedeliveryNotPermittedException::class);
        $sharing->redeliver($row->id);
    }

    public function test_redeliver_missing_row_throws(): void
    {
        $sharing = $this->app->make(SharingService::class);
        $this->expectException(RedeliveryNotPermittedException::class);
        $sharing->redeliver('11111111-1111-1111-1111-111111111111');
    }

    public function test_list_failed_returns_dead_lettered_and_failed_permanent(): void
    {
        OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_DEAD_LETTERED]);
        OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_DEAD_LETTERED]);
        OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_FAILED_PERMANENT]);
        OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_DELIVERED]);
        OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_QUEUED]);

        $sharing = $this->app->make(SharingService::class);
        $failed = $sharing->listFailed();

        $this->assertCount(3, $failed);
        foreach ($failed as $row) {
            $this->assertContains($row->status, [
                OutboundShareMessage::STATUS_DEAD_LETTERED,
                OutboundShareMessage::STATUS_FAILED_PERMANENT,
            ]);
        }
    }
}
```

### Step 2 — Run, expect failure

```bash
vendor/bin/pest tests/Unit/Sharing/Outbox/RedeliveryTest.php
```

### Step 3 — Implement exception + facade methods

```php
<?php
// src/Sharing/Outbox/Exceptions/RedeliveryNotPermittedException.php

namespace AuthService\Helper\Sharing\Outbox\Exceptions;

class RedeliveryNotPermittedException extends \RuntimeException {}
```

Edit `src/Sharing/SharingService.php` and add:

```php
use AuthService\Helper\Sharing\Outbox\Exceptions\RedeliveryNotPermittedException;
use AuthService\Helper\Sharing\Outbox\Jobs\DispatchOutboundShareJob;
use AuthService\Helper\Sharing\Outbox\OutboundShareMessage;
use Illuminate\Database\Eloquent\Collection;

/**
 * Re-attempt delivery of a previously DEAD_LETTERED message. Resets attempts
 * so the row gets the full 5-attempt backoff schedule again. Throws if the
 * caller targets a row that isn't safely re-deliverable.
 */
public function redeliver(string $outboundMessageId): OutboundShareMessage
{
    $row = $this->outbox->getByMessageId($outboundMessageId);

    if ($row === null) {
        throw new RedeliveryNotPermittedException(
            "Outbound message not found: {$outboundMessageId}",
        );
    }

    if ($row->status !== OutboundShareMessage::STATUS_DEAD_LETTERED) {
        throw new RedeliveryNotPermittedException(
            "Cannot redeliver a row in status '{$row->status}'. "
            ."Only DEAD_LETTERED rows are re-deliverable.",
        );
    }

    $row->forceFill([
        'status' => OutboundShareMessage::STATUS_QUEUED,
        'attempts' => 0,
        'next_retry_at' => null,
        'dead_lettered_at' => null,
        'last_response_status' => null,
        // last_error is intentionally preserved as audit context
    ])->save();

    DispatchOutboundShareJob::dispatch($row->id);

    return $row->fresh();
}

/**
 * All terminal-failure rows (dead-lettered + failed-permanent) ordered
 * newest first. Useful for an ops triage UI.
 */
public function listFailed(): Collection
{
    return OutboundShareMessage::query()
        ->whereIn('status', [
            OutboundShareMessage::STATUS_DEAD_LETTERED,
            OutboundShareMessage::STATUS_FAILED_PERMANENT,
        ])
        ->orderByDesc('updated_at')
        ->get();
}
```

If the Sharing facade (created in C4) doesn't already forward arbitrary calls to the underlying service, add explicit `redeliver` + `listFailed` methods to `src/Sharing/Facades/Sharing.php`:

```php
/**
 * @method static \AuthService\Helper\Sharing\Outbox\OutboundShareMessage redeliver(string $outboundMessageId)
 * @method static \Illuminate\Database\Eloquent\Collection listFailed()
 */
```

(Facade magic-method forwarding via `getFacadeAccessor()` returning `SharingService::class` already exposes these — only the docblock changes.)

### Step 4 — Run + commit

```bash
vendor/bin/pest tests/Unit/Sharing/Outbox/RedeliveryTest.php
# Expected: 6 passed.

git add src/Sharing/Outbox/Exceptions/RedeliveryNotPermittedException.php \
        src/Sharing/SharingService.php \
        src/Sharing/Facades/Sharing.php \
        tests/Unit/Sharing/Outbox/RedeliveryTest.php
git commit -m "feat(sharing): phase E5 — DLQ recovery via Sharing::redeliver

Sharing::redeliver(\$id) re-queues a DEAD_LETTERED row (attempts reset
to 0, status → queued, dispatches DispatchOutboundShareJob). Throws
RedeliveryNotPermittedException for DELIVERED / FAILED_PERMANENT /
QUEUED rows (only DEAD_LETTERED is a recoverable terminal state).
Sharing::listFailed() returns dead_lettered + failed_permanent rows
for ops triage.

Phase: E5 of docs/plans/InterProductCommunication-2026-05-27/"
```

## Verification checklist

- [ ] Re-delivering DEAD_LETTERED resets attempts to 0 and dispatches the job
- [ ] Re-delivering DELIVERED / FAILED_PERMANENT throws (terminal-by-design)
- [ ] Re-delivering QUEUED / RETRY_SCHEDULED throws (already in flight)
- [ ] Re-delivering missing row throws (not a silent no-op — operator needs to know)
- [ ] `listFailed()` returns the union of both terminal-failure states
