# Phase E6 — Status surface: `getDeliveryStatus`, `lastInboundFor`, `sharing:purge-delivered`

**Repo:** `auth-service-helper`
**Spec section:** §7 "Source-Side API" + §13 "Edge Cases" (waiting-for-payload)
**Depends on:** E5 (redeliver), D2c (lastInboundFor — already wired)

## Goal

Three operator-facing surfaces on top of the outbox:

1. `Sharing::getDeliveryStatus($shareId)` — aggregate counts per state + last timestamps for a share, suitable for rendering a source-side "delivery status" UI.
2. `Sharing::lastInboundFor($shareId)` — confirms the D2c implementation is the canonical wiring (was stubbed by C4 before D2c shipped).
3. `php artisan sharing:purge-delivered --before=<date>` — housekeeping command that deletes old `delivered` rows (default cutoff: 30 days ago). Keeps the outbox table from growing unbounded.

## Files

- **Create:** `src/Sharing/Outbox/DeliveryStatusSummary.php`
- **Modify:** `src/Sharing/SharingService.php` (add `getDeliveryStatus`, confirm `lastInboundFor` wiring)
- **Create:** `src/Sharing/Console/PurgeDeliveredCommand.php`
- **Test:** `tests/Unit/Sharing/Outbox/DeliveryStatusSummaryTest.php`
- **Test:** `tests/Feature/Sharing/Console/PurgeDeliveredCommandTest.php`

## Steps

### Step 1 — Failing tests

```php
<?php
// tests/Unit/Sharing/Outbox/DeliveryStatusSummaryTest.php

namespace Tests\Unit\Sharing\Outbox;

use AuthService\Helper\Sharing\Outbox\DeliveryStatusSummary;
use AuthService\Helper\Sharing\Outbox\OutboundShareMessage;
use AuthService\Helper\Sharing\SharingService;
use AuthService\Helper\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

class DeliveryStatusSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_delivery_status_aggregates_by_state(): void
    {
        $shareId = 'share-abc';
        Carbon::setTestNow('2026-05-27T12:00:00Z');

        OutboundShareMessage::factory()->create([
            'share_id' => $shareId,
            'status' => OutboundShareMessage::STATUS_QUEUED,
        ]);
        OutboundShareMessage::factory()->create([
            'share_id' => $shareId,
            'status' => OutboundShareMessage::STATUS_RETRY_SCHEDULED,
        ]);
        OutboundShareMessage::factory()->create([
            'share_id' => $shareId,
            'status' => OutboundShareMessage::STATUS_DELIVERED,
            'delivered_at' => Carbon::now()->subMinute(),
            'last_attempt_at' => Carbon::now()->subMinute(),
        ]);
        OutboundShareMessage::factory()->create([
            'share_id' => $shareId,
            'status' => OutboundShareMessage::STATUS_FAILED_PERMANENT,
            'last_attempt_at' => Carbon::now()->subMinutes(2),
        ]);
        OutboundShareMessage::factory()->create([
            'share_id' => $shareId,
            'status' => OutboundShareMessage::STATUS_DEAD_LETTERED,
        ]);

        // unrelated share — must not bleed into counts
        OutboundShareMessage::factory()->create([
            'share_id' => 'other',
            'status' => OutboundShareMessage::STATUS_DELIVERED,
        ]);

        /** @var SharingService $sharing */
        $sharing = $this->app->make(SharingService::class);
        $summary = $sharing->getDeliveryStatus($shareId);

        $this->assertInstanceOf(DeliveryStatusSummary::class, $summary);
        $this->assertEquals($shareId, $summary->shareId);
        $this->assertEquals(2, $summary->pending);       // queued + retry_scheduled
        $this->assertEquals(1, $summary->delivered);
        $this->assertEquals(1, $summary->failed);        // failed_permanent
        $this->assertEquals(1, $summary->deadLettered);
        $this->assertNotNull($summary->lastDeliveredAt);
        $this->assertNotNull($summary->lastAttemptAt);

        $array = $summary->toArray();
        $this->assertEquals($shareId, $array['share_id']);
        $this->assertEquals(2, $array['pending']);
    }

    public function test_get_delivery_status_for_unknown_share_returns_zeroes(): void
    {
        $sharing = $this->app->make(SharingService::class);
        $summary = $sharing->getDeliveryStatus('does-not-exist');

        $this->assertEquals(0, $summary->pending);
        $this->assertEquals(0, $summary->delivered);
        $this->assertEquals(0, $summary->failed);
        $this->assertEquals(0, $summary->deadLettered);
        $this->assertNull($summary->lastDeliveredAt);
    }
}
```

```php
<?php
// tests/Feature/Sharing/Console/PurgeDeliveredCommandTest.php

namespace Tests\Feature\Sharing\Console;

use AuthService\Helper\Sharing\Outbox\OutboundShareMessage;
use AuthService\Helper\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

class PurgeDeliveredCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_purges_delivered_rows_older_than_default_30_days(): void
    {
        Carbon::setTestNow('2026-05-27T12:00:00Z');

        $old = OutboundShareMessage::factory()->create([
            'status' => OutboundShareMessage::STATUS_DELIVERED,
            'delivered_at' => Carbon::now()->subDays(45),
        ]);
        $recent = OutboundShareMessage::factory()->create([
            'status' => OutboundShareMessage::STATUS_DELIVERED,
            'delivered_at' => Carbon::now()->subDays(10),
        ]);
        $deadOld = OutboundShareMessage::factory()->create([
            'status' => OutboundShareMessage::STATUS_DEAD_LETTERED,
            'updated_at' => Carbon::now()->subDays(45),
        ]);

        $this->artisan('sharing:purge-delivered')->assertExitCode(0);

        $this->assertNull(OutboundShareMessage::find($old->id));
        $this->assertNotNull(OutboundShareMessage::find($recent->id));
        $this->assertNotNull(OutboundShareMessage::find($deadOld->id), 'non-delivered rows must not be purged');
    }

    public function test_respects_before_flag(): void
    {
        Carbon::setTestNow('2026-05-27T12:00:00Z');

        $row = OutboundShareMessage::factory()->create([
            'status' => OutboundShareMessage::STATUS_DELIVERED,
            'delivered_at' => Carbon::parse('2026-04-01T00:00:00Z'),
        ]);

        $this->artisan('sharing:purge-delivered', ['--before' => '2026-04-15'])
            ->assertExitCode(0);

        $this->assertNull(OutboundShareMessage::find($row->id));
    }

    public function test_invalid_before_value_fails_with_non_zero_exit(): void
    {
        $this->artisan('sharing:purge-delivered', ['--before' => 'not-a-date'])
            ->assertExitCode(1);
    }
}
```

### Step 2 — Run, expect failure

```bash
vendor/bin/pest tests/Unit/Sharing/Outbox/DeliveryStatusSummaryTest.php \
                tests/Feature/Sharing/Console/PurgeDeliveredCommandTest.php
```

### Step 3 — Implement the summary DTO + facade method

```php
<?php
// src/Sharing/Outbox/DeliveryStatusSummary.php

namespace AuthService\Helper\Sharing\Outbox;

use Illuminate\Support\Carbon;

final class DeliveryStatusSummary
{
    public function __construct(
        public readonly string $shareId,
        public readonly int $pending,
        public readonly int $delivered,
        public readonly int $failed,
        public readonly int $deadLettered,
        public readonly ?Carbon $lastDeliveredAt,
        public readonly ?Carbon $lastAttemptAt,
    ) {}

    public function toArray(): array
    {
        return [
            'share_id' => $this->shareId,
            'pending' => $this->pending,
            'delivered' => $this->delivered,
            'failed' => $this->failed,
            'dead_lettered' => $this->deadLettered,
            'last_delivered_at' => $this->lastDeliveredAt?->toIso8601String(),
            'last_attempt_at' => $this->lastAttemptAt?->toIso8601String(),
        ];
    }
}
```

In `src/Sharing/SharingService.php` add:

```php
use AuthService\Helper\Sharing\Outbox\DeliveryStatusSummary;
use AuthService\Helper\Sharing\Inbox\InboundShareMessage;
use Illuminate\Support\Carbon;

public function getDeliveryStatus(string $shareId): DeliveryStatusSummary
{
    $rows = $this->outbox->getByShareId($shareId);

    $pending = $rows->whereIn('status', OutboundShareMessage::DELIVERABLE_STATES)->count()
        + $rows->where('status', OutboundShareMessage::STATUS_IN_FLIGHT)->count();
    $delivered = $rows->where('status', OutboundShareMessage::STATUS_DELIVERED)->count();
    $failed = $rows->where('status', OutboundShareMessage::STATUS_FAILED_PERMANENT)->count();
    $deadLettered = $rows->where('status', OutboundShareMessage::STATUS_DEAD_LETTERED)->count();

    $lastDelivered = $rows->whereNotNull('delivered_at')->max('delivered_at');
    $lastAttempt = $rows->whereNotNull('last_attempt_at')->max('last_attempt_at');

    return new DeliveryStatusSummary(
        shareId: $shareId,
        pending: $pending,
        delivered: $delivered,
        failed: $failed,
        deadLettered: $deadLettered,
        lastDeliveredAt: $lastDelivered ? Carbon::parse($lastDelivered) : null,
        lastAttemptAt: $lastAttempt ? Carbon::parse($lastAttempt) : null,
    );
}

/**
 * Confirms the canonical D2c implementation. The C4 stub returned null;
 * D2c replaced it. This phase just locks the wiring.
 */
public function lastInboundFor(string $shareId): ?InboundShareMessage
{
    return InboundShareMessage::query()
        ->where('correlation_id', $shareId)
        ->orderByDesc('received_at')
        ->first();
}
```

### Step 4 — Implement the artisan command

```php
<?php
// src/Sharing/Console/PurgeDeliveredCommand.php

namespace AuthService\Helper\Sharing\Console;

use AuthService\Helper\Sharing\Outbox\OutboundShareMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PurgeDeliveredCommand extends Command
{
    protected $signature = 'sharing:purge-delivered
        {--before= : ISO date; rows delivered before this date are removed (default: 30 days ago)}';

    protected $description = 'Remove delivered outbound share messages older than the cutoff date.';

    public function handle(): int
    {
        $beforeArg = $this->option('before');

        try {
            $cutoff = $beforeArg
                ? Carbon::parse($beforeArg)
                : Carbon::now()->subDays(30);
        } catch (\Throwable $e) {
            $this->error("Invalid --before value: {$beforeArg}");
            return 1;
        }

        $deleted = OutboundShareMessage::query()
            ->where('status', OutboundShareMessage::STATUS_DELIVERED)
            ->where('delivered_at', '<', $cutoff)
            ->delete();

        $this->info("Purged {$deleted} delivered outbound share messages (cutoff {$cutoff->toIso8601String()}).");
        return 0;
    }
}
```

Register the command in `SharingServiceProvider::boot()`:

```php
if ($this->app->runningInConsole()) {
    $this->commands([
        \AuthService\Helper\Sharing\Console\PurgeDeliveredCommand::class,
    ]);
}
```

### Step 5 — Run + commit

```bash
vendor/bin/pest tests/Unit/Sharing/Outbox/DeliveryStatusSummaryTest.php \
                tests/Feature/Sharing/Console/PurgeDeliveredCommandTest.php
# Expected: 2 + 3 passed.

git add src/Sharing/Outbox/DeliveryStatusSummary.php \
        src/Sharing/Console/PurgeDeliveredCommand.php \
        src/Sharing/SharingService.php \
        src/Sharing/SharingServiceProvider.php \
        tests/Unit/Sharing/Outbox/DeliveryStatusSummaryTest.php \
        tests/Feature/Sharing/Console/PurgeDeliveredCommandTest.php
git commit -m "feat(sharing): phase E6 — delivery status surface + purge command

Sharing::getDeliveryStatus(\$shareId) returns a DeliveryStatusSummary
DTO with pending/delivered/failed/dead_lettered counts + last
timestamps for a source-side delivery UI. lastInboundFor(\$shareId)
canonicalised on the inbox table (D2c). sharing:purge-delivered
artisan command trims old delivered rows (default cutoff: 30 days).

Phase: E6 of docs/plans/InterProductCommunication-2026-05-27/"
```

## Verification checklist

- [ ] `pending` includes queued + retry_scheduled + in_flight
- [ ] Status counts isolate by share_id (other shares don't bleed in)
- [ ] Unknown share returns all-zeroes summary (no exception)
- [ ] Purge command only touches DELIVERED rows; never dead_lettered or failed_permanent
- [ ] Purge command rejects malformed `--before` with non-zero exit code
- [ ] Command registered in the service provider's `boot()`
