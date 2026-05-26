# Phase E3 — `DispatchOutboundShareJob` skeleton

**Repo:** `auth-service-helper`
**Spec section:** §7 "Source-Side API" + §9 "Source Side"
**Depends on:** E1 (model), E2 (repository), B3 (EnvelopeSigner)

## Goal

The Laravel queued job that picks up a single outbound row, signs the envelope, POSTs it to the peer webhook, and writes the outcome via the repository. This phase ships the SKELETON: happy path → `markDelivered`; any other outcome → stub retry (`scheduleRetry` with 1m delay). Phase E4 swaps the stub for the real retry classifier + backoff schedule.

## Files

- **Create:** `src/Sharing/Outbox/Jobs/DispatchOutboundShareJob.php`
- **Test:** `tests/Unit/Sharing/Outbox/DispatchOutboundShareJobSkeletonTest.php`

## Steps

### Step 1 — Failing job test

```php
<?php
// tests/Unit/Sharing/Outbox/DispatchOutboundShareJobSkeletonTest.php

namespace Tests\Unit\Sharing\Outbox;

use AuthService\Helper\Sharing\Outbox\Jobs\DispatchOutboundShareJob;
use AuthService\Helper\Sharing\Outbox\OutboundShareMessage;
use AuthService\Helper\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

class DispatchOutboundShareJobSkeletonTest extends TestCase
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
    }

    public function test_2xx_response_marks_delivered_and_writes_signature_header(): void
    {
        Http::fake([
            'portify.test/*' => Http::response(['ok' => true], 202),
        ]);

        $row = OutboundShareMessage::factory()->create([
            'peer_slug' => 'portify',
            'status' => OutboundShareMessage::STATUS_QUEUED,
            'attempts' => 0,
        ]);

        (new DispatchOutboundShareJob($row->id))->handle();

        $row->refresh();
        $this->assertEquals(OutboundShareMessage::STATUS_DELIVERED, $row->status);
        $this->assertEquals(1, $row->attempts);
        $this->assertNotNull($row->delivered_at);
        $this->assertNotNull($row->signature_header);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->hasHeader('X-Signature')
                && $request->hasHeader('X-Trust-Key', 'tk')
                && $request->hasHeader('X-Idempotency-Key');
        });
    }

    public function test_non_2xx_response_schedules_retry_in_skeleton(): void
    {
        Http::fake([
            'portify.test/*' => Http::response(['err' => 'down'], 503),
        ]);

        $row = OutboundShareMessage::factory()->create([
            'peer_slug' => 'portify',
            'status' => OutboundShareMessage::STATUS_QUEUED,
            'attempts' => 0,
        ]);

        (new DispatchOutboundShareJob($row->id))->handle();

        $row->refresh();
        $this->assertEquals(OutboundShareMessage::STATUS_RETRY_SCHEDULED, $row->status);
        $this->assertEquals(1, $row->attempts);
        $this->assertEquals(503, $row->last_response_status);
        $this->assertNotNull($row->next_retry_at);
    }

    public function test_terminal_row_is_skipped(): void
    {
        Http::fake(); // no HTTP calls expected

        $row = OutboundShareMessage::factory()->create([
            'peer_slug' => 'portify',
            'status' => OutboundShareMessage::STATUS_DELIVERED,
            'attempts' => 1,
        ]);

        (new DispatchOutboundShareJob($row->id))->handle();

        Http::assertNothingSent();
        $row->refresh();
        $this->assertEquals(OutboundShareMessage::STATUS_DELIVERED, $row->status);
        $this->assertEquals(1, $row->attempts); // not bumped
    }

    public function test_missing_row_is_a_noop(): void
    {
        Http::fake();
        (new DispatchOutboundShareJob('11111111-1111-1111-1111-111111111111'))->handle();
        Http::assertNothingSent();
    }
}
```

### Step 2 — Run, expect failure

```bash
vendor/bin/pest tests/Unit/Sharing/Outbox/DispatchOutboundShareJobSkeletonTest.php
```

### Step 3 — Implement the job

```php
<?php
// src/Sharing/Outbox/Jobs/DispatchOutboundShareJob.php

namespace AuthService\Helper\Sharing\Outbox\Jobs;

use AuthService\Helper\Sharing\Envelope\EnvelopeSigner;
use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use AuthService\Helper\Sharing\Outbox\OutboundShareMessage;
use AuthService\Helper\Sharing\Outbox\SharingOutboxRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

class DispatchOutboundShareJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $outboundMessageId) {}

    public function handle(SharingOutboxRepository $repo = null): void
    {
        $repo = $repo ?? app(SharingOutboxRepository::class);

        $row = $repo->getByMessageId($this->outboundMessageId);
        if ($row === null) {
            return;
        }
        if ($row->isInTerminalState()) {
            return;
        }

        $peer = (array) config("authservice.sharing.peers.{$row->peer_slug}", []);
        if (empty($peer['webhook_url']) || empty($peer['signing_secret'])) {
            $repo->markFailedPermanent($row, null, "Peer config missing for {$row->peer_slug}");
            return;
        }

        $envelope = ShareEnvelope::fromArray($row->envelope_json);
        $signer = new EnvelopeSigner((string) $peer['signing_secret']);
        $signatureHeader = $signer->sign($envelope);

        $row->forceFill(['signature_header' => $signatureHeader])->save();
        $repo->markInFlight($row);

        $headers = [
            'Content-Type' => 'application/json',
            'X-Signature' => $signatureHeader,
            'X-Trust-Key' => (string) ($peer['trust_key'] ?? ''),
            'X-Idempotency-Key' => $row->idempotency_key,
        ];

        try {
            $response = Http::withHeaders($headers)
                ->timeout((int) ($peer['timeout'] ?? 10))
                ->send('POST', (string) $peer['webhook_url'], [
                    'body' => $envelope->toCanonicalJson(),
                ]);
        } catch (Throwable $e) {
            $this->handleFailure($repo, $row, null, $e->getMessage());
            return;
        }

        $status = $response->status();
        if ($status >= 200 && $status < 300) {
            $repo->markDelivered($row);
            return;
        }

        $this->handleFailure($repo, $row, $status, $response->body());
    }

    /**
     * Skeleton retry path — Phase E4 replaces this with a classifier +
     * exponential backoff + DLQ-after-N-attempts.
     */
    protected function handleFailure(
        SharingOutboxRepository $repo,
        OutboundShareMessage $row,
        ?int $status,
        ?string $error,
    ): void {
        $repo->scheduleRetry($row, attemptN: $row->attempts, responseStatus: $status, error: $error);
    }
}
```

### Step 4 — Run + commit

```bash
vendor/bin/pest tests/Unit/Sharing/Outbox/DispatchOutboundShareJobSkeletonTest.php
# Expected: 4 passed.

git add src/Sharing/Outbox/Jobs/DispatchOutboundShareJob.php \
        tests/Unit/Sharing/Outbox/DispatchOutboundShareJobSkeletonTest.php
git commit -m "feat(sharing): phase E3 — DispatchOutboundShareJob skeleton

Queued job that picks one outbound row, signs the envelope, POSTs to
the peer webhook with X-Signature / X-Trust-Key / X-Idempotency-Key,
and on 2xx marks delivered. Any other outcome routes through a stub
that schedules a 1m retry. Phase E4 will replace the stub with the
real classifier + exponential-backoff schedule + DLQ.

Phase: E3 of docs/plans/InterProductCommunication-2026-05-27/"
```

## Verification checklist

- [ ] 2xx → STATUS_DELIVERED + delivered_at + signature_header persisted
- [ ] Non-2xx → STATUS_RETRY_SCHEDULED with last_response_status + next_retry_at
- [ ] Terminal rows skipped (no HTTP)
- [ ] Missing row is a no-op (no exception)
- [ ] Required headers present on outbound POST
