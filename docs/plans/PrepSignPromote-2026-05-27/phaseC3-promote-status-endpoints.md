# Phase C3 — /promote + /status endpoints

**Repo:** `auth-service-helper`
**Depends on:** B2, D1, v1.3 UserShareClient

## Goal

`POST /api/v1/sharing/prep/{prep_id}/promote` — orchestrator confirms external condition (payment), peer creates permanent record. Requires a valid auth-service `share_id` (post-payment trigger). Idempotent re-call returns existing permanent_resource_id.

`GET /api/v1/sharing/prep/{prep_id}/status` — reconciliation read.

## Files

- **Create:** `src/Sharing/Prep/Http/Controllers/PromoteController.php`
- **Create:** `src/Sharing/Prep/PromoteResult.php` (DTO)
- **Test:** `tests/Feature/Sharing/Prep/PromoteControllerTest.php`

## Steps

### Step 1 — Failing test

```php
<?php
// tests/Feature/Sharing/Prep/PromoteControllerTest.php
namespace Tests\Feature\Sharing\Prep;

use AuthService\Helper\Sharing\Client\UserShareClient;
use AuthService\Helper\Sharing\Prep\Events\PrepResourcePromoted;
use AuthService\Helper\Sharing\Prep\Http\Controllers\PromoteController;
use AuthService\Helper\Sharing\Prep\Intents\PrepIntentRegistry;
use AuthService\Helper\Sharing\Prep\PrepResource;
use AuthService\Helper\Sharing\Prep\PromoteResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Mockery;
use Orchestra\Testbench\TestCase;

class PromoteControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [\AuthService\Helper\AuthServiceHelperServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../../database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(PrepIntentRegistry::class)->register(
            slug: 'test_sign',
            handler: new \Tests\Feature\Sharing\Prep\Fixtures\TestSignHandler(),
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_promote_signed_row_creates_permanent_dispatches_event(): void
    {
        Event::fake([PrepResourcePromoted::class]);

        $row = PrepResource::factory()->create([
            'intent' => 'test_sign',
            'status' => 'signed',
            'signed_at' => now(),
            'signed_data' => ['signature' => 'Jane'],
        ]);

        $this->fakeShareLookup(shareId: 'sh-1', valid: true);

        $req = $this->buildPromoteRequest($row->id, [
            'operation' => 'promote',
            'share_id'  => 'sh-1',
            'trigger_proof' => ['payment_id' => 'pay-1'],
        ]);

        $resp = app(PromoteController::class)->promote($req, $row->id);
        $this->assertEquals(200, $resp->getStatusCode());
        $body = $resp->getData(true);
        $this->assertEquals("perm-{$row->id}", $body['permanent_resource_id']);
        $this->assertEquals('active', $body['state']);

        $row->refresh();
        $this->assertEquals('promoted', $row->status);
        $this->assertEquals("perm-{$row->id}", $row->permanent_resource_id);

        Event::assertDispatched(PrepResourcePromoted::class);
    }

    public function test_promote_idempotent_returns_existing_permanent(): void
    {
        $row = PrepResource::factory()->create([
            'status' => 'promoted',
            'permanent_resource_id' => 'perm-99',
            'promoted_at' => now(),
        ]);
        $this->fakeShareLookup(shareId: 'sh-1', valid: true);

        $resp = app(PromoteController::class)->promote(
            $this->buildPromoteRequest($row->id, ['operation' => 'promote', 'share_id' => 'sh-1', 'trigger_proof' => []]),
            $row->id,
        );
        $this->assertEquals(409, $resp->getStatusCode());
        $body = $resp->getData(true);
        $this->assertEquals('already_promoted', $body['error']);
        $this->assertEquals('perm-99', $body['permanent_resource_id']);
    }

    public function test_promote_rejects_unsigned_row(): void
    {
        $row = PrepResource::factory()->create(['status' => 'prepared']);
        $this->fakeShareLookup(shareId: 'sh-1', valid: true);

        $resp = app(PromoteController::class)->promote(
            $this->buildPromoteRequest($row->id, ['operation' => 'promote', 'share_id' => 'sh-1', 'trigger_proof' => []]),
            $row->id,
        );
        $this->assertEquals(409, $resp->getStatusCode());
        $this->assertEquals('not_signed', $resp->getData(true)['error']);
    }

    public function test_promote_rejects_expired_row(): void
    {
        $row = PrepResource::factory()->create([
            'status' => 'signed',
            'signed_at' => now()->subDays(8),
            'expires_at' => now()->subHour(),
        ]);
        $this->fakeShareLookup(shareId: 'sh-1', valid: true);

        $resp = app(PromoteController::class)->promote(
            $this->buildPromoteRequest($row->id, ['operation' => 'promote', 'share_id' => 'sh-1', 'trigger_proof' => []]),
            $row->id,
        );
        $this->assertEquals(404, $resp->getStatusCode());
        $this->assertEquals('prep_expired', $resp->getData(true)['error']);
    }

    public function test_promote_rejects_when_share_id_invalid(): void
    {
        $row = PrepResource::factory()->create([
            'status' => 'signed',
            'signed_at' => now(),
        ]);
        $this->fakeShareLookup(shareId: 'sh-bad', valid: false);

        $resp = app(PromoteController::class)->promote(
            $this->buildPromoteRequest($row->id, ['operation' => 'promote', 'share_id' => 'sh-bad', 'trigger_proof' => []]),
            $row->id,
        );
        $this->assertEquals(403, $resp->getStatusCode());
        $this->assertEquals('share_invalid', $resp->getData(true)['error']);
    }

    public function test_promote_missing_share_id_returns_400(): void
    {
        $row = PrepResource::factory()->create(['status' => 'signed', 'signed_at' => now()]);
        $resp = app(PromoteController::class)->promote(
            $this->buildPromoteRequest($row->id, ['operation' => 'promote', 'trigger_proof' => []]),
            $row->id,
        );
        $this->assertEquals(400, $resp->getStatusCode());
    }

    private function fakeShareLookup(string $shareId, bool $valid): void
    {
        $mock = Mockery::mock(UserShareClient::class);
        if ($valid) {
            $mock->shouldReceive('get')->with($shareId)->andReturn([
                'id' => $shareId,
                'user_id' => 'user-1',
                'target_service_id' => 'tgt-1',
                'status' => 'active',
            ]);
        } else {
            $mock->shouldReceive('get')->with($shareId)->andReturn(null);
        }
        $this->app->instance(UserShareClient::class, $mock);
    }

    private function buildPromoteRequest(string $prepId, array $payload): Request
    {
        $envelope = [
            'envelope_version' => '1',
            'message_id'       => 'msg_' . bin2hex(random_bytes(8)),
            'correlation_id'   => '00000000-0000-0000-0000-000000000010',
            'intent'           => 'prep.promote',
            'intent_version'   => '1.0',
            'source_service_id'=> '11111111-1111-1111-1111-111111111111',
            'target_service_id'=> '22222222-2222-2222-2222-222222222222',
            'user_id'          => '33333333-3333-3333-3333-333333333333',
            'idempotency_key'  => 'studendly:c1:visa-rep',
            'issued_at'        => '2026-05-27T10:00:00Z',
            'payload'          => $payload,
        ];
        $req = Request::create("/api/v1/sharing/prep/{$prepId}/promote", 'POST', content: json_encode($envelope));
        $req->attributes->set('source_service_id', '11111111-1111-1111-1111-111111111111');
        $req->attributes->set('peer_slug', 'studendly');
        $req->headers->set('Content-Type', 'application/json');
        return $req;
    }
}
```

### Step 2 — Implement

```php
<?php
// src/Sharing/Prep/PromoteResult.php
namespace AuthService\Helper\Sharing\Prep;

final class PromoteResult
{
    public function __construct(
        public readonly string $permanentResourceId,
        public readonly string $state,
    ) {}

    public function toArray(): array
    {
        return [
            'permanent_resource_id' => $this->permanentResourceId,
            'state' => $this->state,
        ];
    }
}
```

```php
<?php
// src/Sharing/Prep/Http/Controllers/PromoteController.php
namespace AuthService\Helper\Sharing\Prep\Http\Controllers;

use AuthService\Helper\Sharing\Client\UserShareClient;
use AuthService\Helper\Sharing\Envelope\Exceptions\InvalidEnvelopeException;
use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use AuthService\Helper\Sharing\Prep\Events\PrepResourcePromoted;
use AuthService\Helper\Sharing\Prep\Intents\PrepIntentRegistry;
use AuthService\Helper\Sharing\Prep\PrepResource;
use AuthService\Helper\Sharing\Prep\PrepResourceRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

class PromoteController
{
    public function __construct(
        protected PrepResourceRepository $repo,
        protected PrepIntentRegistry $intents,
        protected UserShareClient $shares,
    ) {}

    public function promote(Request $request, string $prepId): JsonResponse
    {
        // 1. Parse envelope
        try {
            $raw = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($raw)) {
                throw new InvalidEnvelopeException('body must be a JSON object');
            }
            $env = ShareEnvelope::fromArray($raw);
        } catch (\JsonException | InvalidEnvelopeException $e) {
            return $this->error(400, 'invalid_envelope', $e->getMessage());
        }

        $payload = $env->payload;
        if (($payload['operation'] ?? null) !== 'promote') {
            return $this->error(400, 'invalid_operation', 'expected operation=promote');
        }

        $shareId = $payload['share_id'] ?? null;
        if (!$shareId || !is_string($shareId)) {
            return $this->error(400, 'missing_share_id', 'payload.share_id is required (created via Sharing::shareUser post-payment)');
        }

        // 2. Look up the prep entry
        $row = $this->repo->find($prepId);
        if (!$row) {
            return $this->error(404, 'prep_not_found', "no prep entry for {$prepId}");
        }

        // 3. Idempotent retry: already promoted → return existing
        if ($row->isPromoted()) {
            return new JsonResponse([
                'error' => 'already_promoted',
                'permanent_resource_id' => $row->permanent_resource_id,
                'state' => 'active',  // peer-side state machine is product-driven; this is just the prep row's perspective
            ], 409);
        }

        if ($row->hasExpired()) {
            return $this->error(404, 'prep_expired', "prep {$prepId} expired at {$row->expires_at?->toIso8601String()}");
        }

        if ($row->status !== PrepResource::STATUS_SIGNED) {
            return new JsonResponse([
                'error'  => 'not_signed',
                'status' => $row->status,
                'message' => "prep must be in 'signed' state before promote (currently '{$row->status}')",
            ], 409);
        }

        // 4. Validate share_id against auth-service
        $share = null;
        try {
            $share = $this->shares->get($shareId);
        } catch (\Throwable $e) {
            return $this->error(502, 'share_lookup_failed', $e->getMessage());
        }
        if (!$share || ($share['status'] ?? null) !== 'active') {
            return $this->error(403, 'share_invalid', "share {$shareId} not found or not active");
        }

        // 5. Delegate to the intent handler
        if (!$this->intents->has($row->intent)) {
            return $this->error(500, 'no_handler', "intent '{$row->intent}' has no registered handler");
        }

        try {
            $result = $this->intents->get($row->intent)->promote($row, $share);
        } catch (\Throwable $e) {
            return $this->error(500, 'promotion_failed', $e->getMessage());
        }

        // 6. Mark promoted + fire event
        $this->repo->markPromoted($row, permanentResourceId: $result->permanentResourceId);
        Event::dispatch(new PrepResourcePromoted($row->fresh(), $result->permanentResourceId));

        return new JsonResponse([
            'permanent_resource_id' => $result->permanentResourceId,
            'state'                 => $result->state,
        ], 200);
    }

    public function status(Request $request, string $prepId): JsonResponse
    {
        $row = $this->repo->find($prepId);
        if (!$row) {
            return $this->error(404, 'prep_not_found', "no prep entry for {$prepId}");
        }
        return new JsonResponse([
            'prep_id' => $row->id,
            'intent'  => $row->intent,
            'status'  => $row->status,
            'signed_at'   => $row->signed_at?->toIso8601String(),
            'promoted_at' => $row->promoted_at?->toIso8601String(),
            'expires_at'  => $row->expires_at?->toIso8601String(),
            'permanent_resource_id' => $row->permanent_resource_id,
        ], 200);
    }

    protected function error(int $status, string $reason, string $message): JsonResponse
    {
        return new JsonResponse(['error' => $reason, 'message' => $message], $status);
    }
}
```

### Step 3 — Run + commit

```bash
vendor/bin/phpunit tests/Feature/Sharing/Prep/PromoteControllerTest.php
# Expected: 6 passed.

git add src/Sharing/Prep/Http/Controllers/PromoteController.php \
        src/Sharing/Prep/PromoteResult.php \
        tests/Feature/Sharing/Prep/PromoteControllerTest.php
git commit -m "feat(sharing): phase C3 — /promote + /status endpoints

Promote validates: prep entry exists, is in 'signed' state, not expired,
AND share_id resolves to an active auth-service share. Delegates to the
intent handler's promote(), then marks the row promoted + dispatches
PrepResourcePromoted. Idempotent: already-promoted returns 409 with the
existing permanent_resource_id. The share_id requirement is what defers
auth-service share creation to post-payment.

Status endpoint is the reconciliation read for orchestrators that lost
postMessage acks.

Phase: C3 of docs/plans/PrepSignPromote-2026-05-27/"
```

## Verification checklist

- [ ] Missing `share_id` → 400 (not 422 — it's a required parameter, not a payload-schema violation)
- [ ] Share lookup miss/inactive → 403 `share_invalid`
- [ ] Expired prep → 404 `prep_expired`
- [ ] Already promoted → 409 with existing `permanent_resource_id` (idempotent)
- [ ] Handler exception → 500 `promotion_failed` with handler's message; prep row NOT marked promoted (so retry is safe)
