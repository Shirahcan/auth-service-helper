# Phase E4 — Retry schedule + backoff + 4xx/5xx classification

**Repo:** `auth-service-helper`
**Spec section:** §9 "Source Side" (retry table) + §13 "Edge Cases"
**Depends on:** E3 (job skeleton)

## Goal

Replace E3's stub retry path with the real classifier + backoff schedule:

- **Transient** (`5xx`, `408`, `429`, network exception) → schedule retry per the table below.
- **Permanent** (any `4xx` except 408/429) → mark `failed_permanent` immediately (no DLQ — the destination explicitly rejected the request).
- **Attempts exhausted** (>5 transient retries) → `dead_lettered`.

Backoff schedule (delay BEFORE the Nth retry):

| Attempt that just failed | Delay before next try |
|---|---|
| 1 | 1 minute |
| 2 | 5 minutes |
| 3 | 30 minutes |
| 4 | 2 hours |
| 5 | 12 hours |
| 6+ | (no retry — dead-letter) |

## Files

- **Create:** `src/Sharing/Outbox/RetryScheduler.php`
- **Modify:** `src/Sharing/Outbox/Jobs/DispatchOutboundShareJob.php` (replace `handleFailure()` stub)
- **Modify:** `src/Sharing/Outbox/SharingOutboxRepository.php` (`scheduleRetry()` accepts the computed delay)
- **Test:** `tests/Unit/Sharing/Outbox/RetrySchedulerTest.php`
- **Test:** `tests/Unit/Sharing/Outbox/DispatchOutboundShareJobRetryTest.php`

## Steps

### Step 1 — Failing tests

```php
<?php
// tests/Unit/Sharing/Outbox/RetrySchedulerTest.php

namespace Tests\Unit\Sharing\Outbox;

use AuthService\Helper\Sharing\Outbox\RetryScheduler;
use PHPUnit\Framework\TestCase;

class RetrySchedulerTest extends TestCase
{
    public function test_schedule_for_attempts_1_through_5(): void
    {
        $this->assertEquals(60, RetryScheduler::nextDelayForAttempt(1));
        $this->assertEquals(300, RetryScheduler::nextDelayForAttempt(2));
        $this->assertEquals(1800, RetryScheduler::nextDelayForAttempt(3));
        $this->assertEquals(7200, RetryScheduler::nextDelayForAttempt(4));
        $this->assertEquals(43200, RetryScheduler::nextDelayForAttempt(5));
    }

    public function test_attempts_beyond_five_return_null(): void
    {
        $this->assertNull(RetryScheduler::nextDelayForAttempt(6));
        $this->assertNull(RetryScheduler::nextDelayForAttempt(99));
    }

    public function test_transient_classification_5xx(): void
    {
        foreach ([500, 502, 503, 504] as $s) {
            $this->assertTrue(RetryScheduler::isTransient($s, null), "{$s} should be transient");
        }
    }

    public function test_transient_classification_408_429(): void
    {
        $this->assertTrue(RetryScheduler::isTransient(408, null));
        $this->assertTrue(RetryScheduler::isTransient(429, null));
    }

    public function test_permanent_classification_other_4xx(): void
    {
        foreach ([400, 401, 403, 404, 410, 422] as $s) {
            $this->assertFalse(RetryScheduler::isTransient($s, null), "{$s} should be permanent");
        }
    }

    public function test_2xx_is_not_transient(): void
    {
        $this->assertFalse(RetryScheduler::isTransient(200, null));
    }

    public function test_network_exception_is_transient(): void
    {
        $this->assertTrue(RetryScheduler::isTransient(null, new \RuntimeException('timed out')));
    }
}
```

```php
<?php
// tests/Unit/Sharing/Outbox/DispatchOutboundShareJobRetryTest.php

namespace Tests\Unit\Sharing\Outbox;

use AuthService\Helper\Sharing\Outbox\Jobs\DispatchOutboundShareJob;
use AuthService\Helper\Sharing\Outbox\OutboundShareMessage;
use AuthService\Helper\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

class DispatchOutboundShareJobRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('authservice.sharing.peers.portify', [
            'webhook_url' => 'https://portify.test/api/v1/inbound/user-share',
            'signing_secret' => 'shh',
            'trust_key' => 'tk',
            'target_service_id' => '00000000-0000-0000-0000-000000000002',
        ]);
        Carbon::setTestNow('2026-05-27T12:00:00Z');
    }

    public function test_5xx_on_first_attempt_schedules_1min(): void
    {
        Http::fake(['portify.test/*' => Http::response('', 503)]);
        $row = OutboundShareMessage::factory()->create([
            'peer_slug' => 'portify',
            'status' => OutboundShareMessage::STATUS_QUEUED,
            'attempts' => 0,
        ]);

        (new DispatchOutboundShareJob($row->id))->handle();
        $row->refresh();

        $this->assertEquals(OutboundShareMessage::STATUS_RETRY_SCHEDULED, $row->status);
        $this->assertEquals(1, $row->attempts);
        $this->assertEquals(
            Carbon::now()->addSeconds(60)->timestamp,
            $row->next_retry_at->timestamp,
        );
    }

    public function test_5xx_on_second_attempt_schedules_5min(): void
    {
        Http::fake(['portify.test/*' => Http::response('', 503)]);
        $row = OutboundShareMessage::factory()->create([
            'peer_slug' => 'portify',
            'status' => OutboundShareMessage::STATUS_RETRY_SCHEDULED,
            'attempts' => 1,
            'next_retry_at' => Carbon::now()->subMinute(),
        ]);

        (new DispatchOutboundShareJob($row->id))->handle();
        $row->refresh();

        $this->assertEquals(OutboundShareMessage::STATUS_RETRY_SCHEDULED, $row->status);
        $this->assertEquals(2, $row->attempts);
        $this->assertEquals(
            Carbon::now()->addSeconds(300)->timestamp,
            $row->next_retry_at->timestamp,
        );
    }

    public function test_5xx_after_five_attempts_dead_letters(): void
    {
        Http::fake(['portify.test/*' => Http::response('', 503)]);
        $row = OutboundShareMessage::factory()->create([
            'peer_slug' => 'portify',
            'status' => OutboundShareMessage::STATUS_RETRY_SCHEDULED,
            'attempts' => 5,
            'next_retry_at' => Carbon::now()->subMinute(),
        ]);

        (new DispatchOutboundShareJob($row->id))->handle();
        $row->refresh();

        $this->assertEquals(OutboundShareMessage::STATUS_DEAD_LETTERED, $row->status);
        $this->assertEquals(6, $row->attempts);
        $this->assertNotNull($row->dead_lettered_at);
    }

    public function test_4xx_is_failed_permanent_immediately(): void
    {
        Http::fake(['portify.test/*' => Http::response('', 422)]);
        $row = OutboundShareMessage::factory()->create([
            'peer_slug' => 'portify',
            'status' => OutboundShareMessage::STATUS_QUEUED,
            'attempts' => 0,
        ]);

        (new DispatchOutboundShareJob($row->id))->handle();
        $row->refresh();

        $this->assertEquals(OutboundShareMessage::STATUS_FAILED_PERMANENT, $row->status);
        $this->assertEquals(1, $row->attempts);
        $this->assertEquals(422, $row->last_response_status);
    }

    public function test_429_is_retried_not_failed_permanent(): void
    {
        Http::fake(['portify.test/*' => Http::response('', 429)]);
        $row = OutboundShareMessage::factory()->create([
            'peer_slug' => 'portify',
            'status' => OutboundShareMessage::STATUS_QUEUED,
            'attempts' => 0,
        ]);

        (new DispatchOutboundShareJob($row->id))->handle();
        $row->refresh();

        $this->assertEquals(OutboundShareMessage::STATUS_RETRY_SCHEDULED, $row->status);
    }
}
```

### Step 2 — Run, expect failure

```bash
vendor/bin/pest tests/Unit/Sharing/Outbox/RetrySchedulerTest.php \
                tests/Unit/Sharing/Outbox/DispatchOutboundShareJobRetryTest.php
```

### Step 3 — Implement `RetryScheduler`

```php
<?php
// src/Sharing/Outbox/RetryScheduler.php

namespace AuthService\Helper\Sharing\Outbox;

use Throwable;

final class RetryScheduler
{
    /** Delay (seconds) BEFORE the next retry, indexed by the attempt that just failed. */
    public const SCHEDULE = [
        1 => 60,        // 1 min
        2 => 300,       // 5 min
        3 => 1800,      // 30 min
        4 => 7200,      // 2 h
        5 => 43200,     // 12 h
    ];

    /** Return the delay before the next retry, or null if attempts are exhausted. */
    public static function nextDelayForAttempt(int $attempt): ?int
    {
        return self::SCHEDULE[$attempt] ?? null;
    }

    /**
     * Classify a delivery outcome:
     *   - 5xx        → transient
     *   - 408 / 429  → transient
     *   - other 4xx  → permanent
     *   - 2xx        → not transient (job uses this AFTER detecting non-2xx)
     *   - network/   → transient
     *     exception
     */
    public static function isTransient(?int $responseStatus, ?Throwable $e): bool
    {
        if ($e !== null && $responseStatus === null) {
            return true;
        }
        if ($responseStatus === null) {
            return true;
        }
        if ($responseStatus >= 500 && $responseStatus < 600) {
            return true;
        }
        if (in_array($responseStatus, [408, 429], true)) {
            return true;
        }
        return false;
    }
}
```

### Step 4 — Replace the job's `handleFailure()` + repository `scheduleRetry()`

In `src/Sharing/Outbox/SharingOutboxRepository.php`, widen `scheduleRetry()` to accept the delay:

```php
public function scheduleRetry(
    OutboundShareMessage $row,
    int $attemptN,
    ?int $responseStatus,
    ?string $error,
    ?int $delaySeconds = null,
): void {
    $delaySeconds = $delaySeconds ?? 60;

    $row->forceFill([
        'status' => OutboundShareMessage::STATUS_RETRY_SCHEDULED,
        'next_retry_at' => Carbon::now()->addSeconds($delaySeconds),
        'last_response_status' => $responseStatus,
        'last_error' => $error,
    ])->save();
}
```

In `src/Sharing/Outbox/Jobs/DispatchOutboundShareJob.php`, replace `handleFailure()`:

```php
use AuthService\Helper\Sharing\Outbox\RetryScheduler;

protected function handleFailure(
    SharingOutboxRepository $repo,
    OutboundShareMessage $row,
    ?int $status,
    ?string $error,
    ?Throwable $exception = null,
): void {
    if (!RetryScheduler::isTransient($status, $exception)) {
        $repo->markFailedPermanent($row, $status, $error);
        return;
    }

    $delay = RetryScheduler::nextDelayForAttempt($row->attempts);
    if ($delay === null) {
        $repo->deadLetter($row, "Attempts exhausted after {$row->attempts} tries: ".(string) $error);
        return;
    }

    $repo->scheduleRetry(
        $row,
        attemptN: $row->attempts,
        responseStatus: $status,
        error: $error,
        delaySeconds: $delay,
    );
}
```

Also update the `handle()` catch block to pass the exception through:

```php
} catch (Throwable $e) {
    $this->handleFailure($repo, $row, null, $e->getMessage(), $e);
    return;
}
```

### Step 5 — Run + commit

```bash
vendor/bin/pest tests/Unit/Sharing/Outbox/RetrySchedulerTest.php \
                tests/Unit/Sharing/Outbox/DispatchOutboundShareJobRetryTest.php
# Expected: 7 + 5 passed.

git add src/Sharing/Outbox/RetryScheduler.php \
        src/Sharing/Outbox/SharingOutboxRepository.php \
        src/Sharing/Outbox/Jobs/DispatchOutboundShareJob.php \
        tests/Unit/Sharing/Outbox/RetrySchedulerTest.php \
        tests/Unit/Sharing/Outbox/DispatchOutboundShareJobRetryTest.php
git commit -m "feat(sharing): phase E4 — retry schedule + 4xx/5xx classification

RetryScheduler implements the canonical 1m/5m/30m/2h/12h backoff and
classifies outcomes: 5xx + 408 + 429 + network exception → transient
(retry); other 4xx → failed_permanent immediately; >5 transient retries
→ dead_lettered. Job's handleFailure() now routes via the classifier;
repository.scheduleRetry() takes an explicit delaySeconds.

Phase: E4 of docs/plans/InterProductCommunication-2026-05-27/"
```

## Verification checklist

- [ ] Backoff numbers exactly match the spec table
- [ ] 422 / 404 / 401 → failed_permanent (no retry)
- [ ] 408 / 429 → retry_scheduled
- [ ] 5xx after attempt 5 → dead_lettered (no further retries)
- [ ] Network exception → transient (retry)
