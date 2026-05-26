# Phase D2b — Webhook controller (idempotency + persist)

**Repo:** `auth-service-helper`
**Spec section:** §7 controller responsibilities (2) + (4); §10 "Idempotency Model"
**Depends on:** B2 (ShareEnvelope), B5 (IdempotencyGuard contract), D1 (table + model), D2a (middleware sets `source_service_id`)

## Goal

Inbound controller that — after the middleware has authenticated and verified the signature — parses the body into a `ShareEnvelope`, checks idempotency against the inbox UNIQUE constraint, and persists the row with `status=received`. Schema validation + event dispatch happen in D2c (kept separate so this step's tests stay narrow). Also implements the Eloquent-backed `IdempotencyStore` that D2a left abstract.

## Files

- **Create:** `src/Sharing/Inbox/Http/Controllers/InboundShareWebhookController.php`
- **Create:** `src/Sharing/Envelope/Stores/EloquentIdempotencyStore.php`
- **Test:** `tests/Feature/Sharing/Inbox/InboundShareWebhookPersistTest.php`

## Steps

### Step 1 — Write the failing controller test

```php
<?php
// tests/Feature/Sharing/Inbox/InboundShareWebhookPersistTest.php

namespace Tests\Feature\Sharing\Inbox;

use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use AuthService\Helper\Sharing\Inbox\Http\Controllers\InboundShareWebhookController;
use AuthService\Helper\Sharing\Inbox\InboundShareMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;

class InboundShareWebhookPersistTest extends TestCase
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

    public function test_persists_envelope_with_received_status_and_returns_202(): void
    {
        $env = $this->fakeEnv('k-unique-1');
        $req = $this->buildRequest($env, sourceServiceId: $this->sourceId());

        /** @var InboundShareWebhookController $ctrl */
        $ctrl = $this->app->make(InboundShareWebhookController::class);
        $resp = $ctrl->receive($req);

        $this->assertEquals(202, $resp->getStatusCode());
        $body = $resp->getData(true);
        $this->assertEquals($env->messageId, $body['message_id']);
        $this->assertEquals('received', $body['status']);

        $row = InboundShareMessage::query()
            ->where('source_service_id', $this->sourceId())
            ->where('idempotency_key', 'k-unique-1')
            ->first();

        $this->assertNotNull($row);
        $this->assertEquals('received', $row->processing_status);
        $this->assertEquals($env->intent, $row->intent);
        $this->assertEquals($env->payload, $row->payload);
        $this->assertEquals('1', $row->envelope_version);
    }

    public function test_duplicate_idempotency_key_returns_200_already_processed(): void
    {
        $env = $this->fakeEnv('dup-key');

        $first = $this->app->make(InboundShareWebhookController::class)
            ->receive($this->buildRequest($env, $this->sourceId()));
        $this->assertEquals(202, $first->getStatusCode());

        // Replay the same envelope (same source + same idempotency_key)
        $second = $this->app->make(InboundShareWebhookController::class)
            ->receive($this->buildRequest($env, $this->sourceId()));

        $this->assertEquals(200, $second->getStatusCode());
        $body = $second->getData(true);
        $this->assertTrue($body['already_processed']);

        // Exactly one row should have been persisted (UNIQUE blocks the dup)
        $count = InboundShareMessage::where('source_service_id', $this->sourceId())
            ->where('idempotency_key', 'dup-key')
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_same_idempotency_key_from_different_source_is_independent(): void
    {
        $env = $this->fakeEnv('shared-key');

        $r1 = $this->app->make(InboundShareWebhookController::class)
            ->receive($this->buildRequest($env, '00000000-0000-0000-0000-00000000AAAA'));
        $r2 = $this->app->make(InboundShareWebhookController::class)
            ->receive($this->buildRequest($env, '00000000-0000-0000-0000-00000000BBBB'));

        $this->assertEquals(202, $r1->getStatusCode());
        $this->assertEquals(202, $r2->getStatusCode());
        $this->assertEquals(2, InboundShareMessage::where('idempotency_key', 'shared-key')->count());
    }

    public function test_malformed_envelope_returns_400(): void
    {
        $req = Request::create('/inbound/user-share', 'POST', content: '{"not": "an envelope"}');
        $req->attributes->set('source_service_id', $this->sourceId());
        $req->attributes->set('peer_slug', 'studendly');
        $req->headers->set('X-Signature', 't=1,v1=' . str_repeat('a', 64));

        $resp = $this->app->make(InboundShareWebhookController::class)->receive($req);
        $this->assertEquals(400, $resp->getStatusCode());
    }

    private function sourceId(): string
    {
        return '00000000-0000-0000-0000-000000000001';
    }

    private function fakeEnv(string $idempotencyKey): ShareEnvelope
    {
        return ShareEnvelope::fromArray([
            'envelope_version' => '1',
            'message_id' => 'msg_' . bin2hex(random_bytes(8)),
            'correlation_id' => '00000000-0000-0000-0000-000000000010',
            'intent' => 'service_purchase',
            'intent_version' => '1.0',
            'source_service_id' => $this->sourceId(),
            'target_service_id' => '00000000-0000-0000-0000-000000000002',
            'user_id' => '00000000-0000-0000-0000-000000000003',
            'idempotency_key' => $idempotencyKey,
            'issued_at' => '2026-05-27T10:00:00Z',
            'payload' => ['order_id' => 'ord_x'],
        ]);
    }

    private function buildRequest(ShareEnvelope $env, string $sourceServiceId): Request
    {
        $req = Request::create('/inbound/user-share', 'POST', content: $env->toCanonicalJson());
        $req->attributes->set('source_service_id', $sourceServiceId);
        $req->attributes->set('peer_slug', 'studendly');
        $req->headers->set('X-Signature', 't=' . time() . ',v1=' . str_repeat('a', 64));
        $req->headers->set('Content-Type', 'application/json');
        return $req;
    }
}
```

### Step 2 — Run, expect failure

```bash
vendor/bin/pest tests/Feature/Sharing/Inbox/InboundShareWebhookPersistTest.php
```

Expected: controller class not found.

### Step 3 — Implement the Eloquent idempotency store

```php
<?php
// src/Sharing/Envelope/Stores/EloquentIdempotencyStore.php

namespace AuthService\Helper\Sharing\Envelope\Stores;

use AuthService\Helper\Sharing\Envelope\Contracts\IdempotencyStore;
use AuthService\Helper\Sharing\Inbox\InboundShareMessage;

class EloquentIdempotencyStore implements IdempotencyStore
{
    public function exists(string $sourceServiceId, string $idempotencyKey): bool
    {
        return InboundShareMessage::query()
            ->where('source_service_id', $sourceServiceId)
            ->where('idempotency_key', $idempotencyKey)
            ->exists();
    }

    /**
     * No-op: the row is inserted by the controller (UNIQUE constraint is the
     * real guard). This method exists only to satisfy the contract for
     * non-DB-backed test stores.
     */
    public function record(string $sourceServiceId, string $idempotencyKey, string $messageId): void
    {
        // Intentionally empty — see class docblock.
    }
}
```

Also bind in `SharingServiceProvider::register()`:

```php
$this->app->bind(
    \AuthService\Helper\Sharing\Envelope\Contracts\IdempotencyStore::class,
    \AuthService\Helper\Sharing\Envelope\Stores\EloquentIdempotencyStore::class,
);
```

### Step 4 — Implement the controller

```php
<?php
// src/Sharing/Inbox/Http/Controllers/InboundShareWebhookController.php

namespace AuthService\Helper\Sharing\Inbox\Http\Controllers;

use AuthService\Helper\Sharing\Envelope\Exceptions\InvalidEnvelopeException;
use AuthService\Helper\Sharing\Envelope\IdempotencyGuard;
use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use AuthService\Helper\Sharing\Inbox\InboundShareMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InboundShareWebhookController
{
    public function __construct(
        protected IdempotencyGuard $idempotency,
    ) {}

    public function receive(Request $request): JsonResponse
    {
        // 1. Parse envelope
        try {
            $raw = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($raw)) {
                throw new InvalidEnvelopeException('Envelope body must be a JSON object');
            }
            $env = ShareEnvelope::fromArray($raw);
        } catch (\JsonException | InvalidEnvelopeException $e) {
            return $this->error(400, 'invalid_envelope', $e->getMessage());
        }

        $sourceServiceId = (string) $request->attributes->get('source_service_id');
        $signatureHeader = (string) $request->header('X-Signature', '');

        // 2. Idempotency check — UNIQUE(source_service_id, idempotency_key)
        if ($this->idempotency->hasSeen($sourceServiceId, $env->idempotencyKey)) {
            return new JsonResponse([
                'message_id' => $env->messageId,
                'already_processed' => true,
            ], 200);
        }

        // 3. Persist with status=received
        try {
            $row = InboundShareMessage::create([
                'id' => (string) Str::uuid(),
                'envelope_version' => $env->envelopeVersion,
                'message_id' => $env->messageId,
                'correlation_id' => $env->correlationId,
                'intent' => $env->intent,
                'intent_version' => $env->intentVersion,
                'source_service_id' => $sourceServiceId,
                'target_service_id' => $env->targetServiceId,
                'user_id' => $env->userId,
                'idempotency_key' => $env->idempotencyKey,
                'issued_at' => $env->issuedAt,
                'payload' => $env->payload,
                'signature_header' => $signatureHeader,
                'headers' => $this->captureRelevantHeaders($request),
                'received_at' => now(),
                'processing_status' => 'received',
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Race: a parallel request inserted the row between our hasSeen() and now.
            // Treat as duplicate — same outcome as the fast path.
            return new JsonResponse([
                'message_id' => $env->messageId,
                'already_processed' => true,
            ], 200);
        }

        // Validation + event dispatch happen in D2c (subclass/override extends this).
        return new JsonResponse([
            'message_id' => $row->message_id,
            'status' => 'received',
        ], 202);
    }

    /**
     * @return array<string, string|array<int, string>>
     */
    protected function captureRelevantHeaders(Request $request): array
    {
        return collect($request->headers->all())
            ->only([
                'x-trust-key',
                'x-signature',
                'content-type',
                'user-agent',
            ])
            ->all();
    }

    protected function error(int $status, string $reason, string $message): JsonResponse
    {
        return new JsonResponse([
            'error' => $reason,
            'message' => $message,
        ], $status);
    }
}
```

### Step 5 — Run + commit

```bash
vendor/bin/pest tests/Feature/Sharing/Inbox/InboundShareWebhookPersistTest.php
# Expected: 4 passed.

git add src/Sharing/Inbox/Http/Controllers/InboundShareWebhookController.php \
        src/Sharing/Envelope/Stores/EloquentIdempotencyStore.php \
        src/Sharing/SharingServiceProvider.php \
        tests/Feature/Sharing/Inbox/InboundShareWebhookPersistTest.php
git commit -m "feat(sharing): phase D2b — webhook persist + idempotency

Controller parses ShareEnvelope, checks (source_service_id,
idempotency_key) against the inbox UNIQUE constraint via the new
EloquentIdempotencyStore, persists with status=received, returns 202
{message_id, status}. Duplicate → 200 {already_processed: true}.
Catches UniqueConstraintViolationException to handle the
hasSeen()→insert race window. Schema validation + event dispatch in D2c.

Phase: D2b of docs/plans/InterProductCommunication-2026-05-27/"
```

## Verification checklist

- [ ] Happy path: row persisted with `processing_status='received'`, 202 returned
- [ ] Replay path: second call → 200 `{already_processed: true}`, still only 1 row
- [ ] Same idempotency_key from a DIFFERENT source persists independently
- [ ] Malformed envelope → 400 `invalid_envelope`, no row inserted
- [ ] Race window covered by `UniqueConstraintViolationException` catch
