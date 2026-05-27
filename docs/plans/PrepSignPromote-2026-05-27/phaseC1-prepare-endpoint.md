# Phase C1 — /prepare endpoint

**Repo:** `auth-service-helper`
**Depends on:** B2 (repository), v1.3 VerifyShareEnvelopeSignature middleware

## Goal

`POST /api/v1/sharing/prep/prepare` — orchestrator commissions a temp resource on the peer. HMAC-signed via v1.3 middleware. Idempotent on `(source_service_id, idempotency_key)`. Returns `prep_id` + `embed_url` + `expires_at`.

## Files

- **Create:** `src/Sharing/Prep/Http/Controllers/PrepareController.php`
- **Test:** `tests/Feature/Sharing/Prep/PrepareControllerTest.php`

## Steps

### Step 1 — Failing test

```php
<?php
// tests/Feature/Sharing/Prep/PrepareControllerTest.php
namespace Tests\Feature\Sharing\Prep;

use AuthService\Helper\Sharing\Prep\Events\PrepResourceCreated;
use AuthService\Helper\Sharing\Prep\Http\Controllers\PrepareController;
use AuthService\Helper\Sharing\Prep\PrepResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;

class PrepareControllerTest extends TestCase
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

    public function test_creates_new_prep_returns_embed_url(): void
    {
        Event::fake([PrepResourceCreated::class]);
        config()->set('authservice.sharing.prep.embed_base_url', 'https://portify.test');

        $req = $this->buildRequest([
            'operation' => 'prepare',
            'intent_slug' => 'agreement_sign',
            'source_resource' => ['type' => 'checkout', 'id' => 'c1'],
            'student_data'    => ['external_id' => 'stu_1', 'email' => 'a@b.com', 'name' => 'A'],
            'payload'         => ['agreement_slug' => 'visa-rep-agreement'],
            'return_to'       => 'https://studendly.test/done',
        ], idempotencyKey: 'studendly:c1:visa-rep');

        $resp = app(PrepareController::class)->prepare($req);

        $this->assertEquals(200, $resp->getStatusCode());
        $body = $resp->getData(true);
        $this->assertNotEmpty($body['prep_id']);
        $this->assertEquals('prepared', $body['status']);
        $this->assertStringStartsWith('https://portify.test/sharing/embed/agreement_sign/', $body['embed_url']);

        $this->assertDatabaseHas('prep_resources', [
            'idempotency_key' => 'studendly:c1:visa-rep',
            'intent' => 'agreement_sign',
            'status' => 'prepared',
        ]);

        Event::assertDispatched(PrepResourceCreated::class);
    }

    public function test_idempotent_returns_existing_no_event(): void
    {
        Event::fake([PrepResourceCreated::class]);
        config()->set('authservice.sharing.prep.embed_base_url', 'https://portify.test');

        $first  = app(PrepareController::class)->prepare($this->buildRequest(['operation' => 'prepare', 'intent_slug' => 'agreement_sign', 'source_resource' => [], 'student_data' => [], 'payload' => []], 'k'));
        Event::fake([PrepResourceCreated::class]);  // reset
        $second = app(PrepareController::class)->prepare($this->buildRequest(['operation' => 'prepare', 'intent_slug' => 'agreement_sign', 'source_resource' => [], 'student_data' => [], 'payload' => []], 'k'));

        $this->assertEquals(
            $first->getData(true)['prep_id'],
            $second->getData(true)['prep_id'],
        );
        $this->assertEquals(1, PrepResource::count());
        Event::assertNotDispatched(PrepResourceCreated::class);
    }

    public function test_missing_intent_slug_returns_400(): void
    {
        $req = $this->buildRequest(['operation' => 'prepare', 'source_resource' => [], 'student_data' => [], 'payload' => []], 'k');
        $resp = app(PrepareController::class)->prepare($req);
        $this->assertEquals(400, $resp->getStatusCode());
    }

    public function test_unknown_intent_slug_returns_400(): void
    {
        $req = $this->buildRequest(['operation' => 'prepare', 'intent_slug' => 'unknown_intent', 'source_resource' => [], 'student_data' => [], 'payload' => []], 'k');
        $resp = app(PrepareController::class)->prepare($req);
        $this->assertEquals(400, $resp->getStatusCode());
    }

    private function buildRequest(array $payload, string $idempotencyKey): Request
    {
        $envelope = [
            'envelope_version' => '1',
            'message_id'       => 'msg_' . bin2hex(random_bytes(8)),
            'correlation_id'   => '00000000-0000-0000-0000-000000000010',
            'intent'           => 'prep.prepare',
            'intent_version'   => '1.0',
            'source_service_id'=> '11111111-1111-1111-1111-111111111111',
            'target_service_id'=> '22222222-2222-2222-2222-222222222222',
            'user_id'          => '33333333-3333-3333-3333-333333333333',
            'idempotency_key'  => $idempotencyKey,
            'issued_at'        => '2026-05-27T10:00:00Z',
            'payload'          => $payload,
        ];
        $req = Request::create('/api/v1/sharing/prep/prepare', 'POST', content: json_encode($envelope));
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
// src/Sharing/Prep/Http/Controllers/PrepareController.php
namespace AuthService\Helper\Sharing\Prep\Http\Controllers;

use AuthService\Helper\Sharing\Envelope\Exceptions\InvalidEnvelopeException;
use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use AuthService\Helper\Sharing\Prep\Events\PrepResourceCreated;
use AuthService\Helper\Sharing\Prep\Intents\PrepIntentRegistry;
use AuthService\Helper\Sharing\Prep\PrepResource;
use AuthService\Helper\Sharing\Prep\PrepResourceRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

class PrepareController
{
    public function __construct(
        protected PrepResourceRepository $repo,
        protected PrepIntentRegistry $intents,
    ) {}

    public function prepare(Request $request): JsonResponse
    {
        // 1. Parse envelope (the VerifyShareEnvelopeSignature middleware has
        //    already validated signature + replay window; here we just decode).
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
        if (($payload['operation'] ?? null) !== 'prepare') {
            return $this->error(400, 'invalid_operation', "expected operation=prepare");
        }

        $intentSlug = $payload['intent_slug'] ?? null;
        if (!$intentSlug || !is_string($intentSlug)) {
            return $this->error(400, 'missing_intent_slug', 'payload.intent_slug is required');
        }
        if (!$this->intents->has($intentSlug)) {
            return $this->error(400, 'unknown_intent_slug', "no handler registered for intent '{$intentSlug}'");
        }

        $sourceServiceId = (string) ($request->attributes->get('source_service_id') ?? $env->sourceServiceId);

        // 2. Upsert (idempotent on (source_service_id, idempotency_key))
        $existing = $this->repo->findByIdempotency($sourceServiceId, $env->idempotencyKey);

        $row = $this->repo->upsert(
            sourceServiceId: $sourceServiceId,
            idempotencyKey:  $env->idempotencyKey,
            intent:          $intentSlug,
            intentVersion:   $payload['intent_version'] ?? '1.0',
            sourceResource:  (array) ($payload['source_resource'] ?? []),
            studentData:     (array) ($payload['student_data'] ?? []),
            payload:         (array) ($payload['payload'] ?? []),
            returnTo:        $payload['return_to'] ?? null,
            ttlPreparedHours: (int) config('authservice.sharing.prep.ttl_prepared_hours', 24),
        );

        // 3. Fire event ONLY for newly-created rows
        if (!$existing) {
            Event::dispatch(new PrepResourceCreated($row));
        }

        return new JsonResponse([
            'prep_id'    => $row->id,
            'embed_url'  => $this->buildEmbedUrl($intentSlug, $row->id),
            'expires_at' => $row->expires_at?->toIso8601String(),
            'status'     => $row->status,
        ], 200);
    }

    protected function buildEmbedUrl(string $intentSlug, string $prepId): string
    {
        $base = rtrim((string) config(
            'authservice.sharing.prep.embed_base_url',
            (string) config('app.url'),
        ), '/');
        return "{$base}/sharing/embed/{$intentSlug}/{$prepId}";
    }

    protected function error(int $status, string $reason, string $message): JsonResponse
    {
        return new JsonResponse(['error' => $reason, 'message' => $message], $status);
    }
}
```

### Step 3 — Run + commit

```bash
vendor/bin/phpunit tests/Feature/Sharing/Prep/PrepareControllerTest.php
# Expected: 4 passed.

git add src/Sharing/Prep/Http/Controllers/PrepareController.php \
        tests/Feature/Sharing/Prep/PrepareControllerTest.php
git commit -m "feat(sharing): phase C1 — /prepare endpoint

POST /api/v1/sharing/prep/prepare. Validates the envelope (middleware
already verified HMAC + replay window) + dispatches a PrepResourceCreated
event ONLY for newly-created rows. Idempotent re-calls return the same
prep_id + embed_url; never duplicate a row.

Phase: C1 of docs/plans/PrepSignPromote-2026-05-27/"
```

## Verification checklist

- [ ] Idempotent re-call returns same prep_id, does NOT re-dispatch the Created event
- [ ] Unknown intent_slug → 400 `unknown_intent_slug`
- [ ] Embed URL respects `authservice.sharing.prep.embed_base_url` config (falls back to `app.url`)
- [ ] Row's `expires_at` honors `ttl_prepared_hours` config (default 24)
