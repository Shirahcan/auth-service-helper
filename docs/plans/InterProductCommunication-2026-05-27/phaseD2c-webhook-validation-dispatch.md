# Phase D2c — Schema validation + event dispatch + InboundHandoffExchangeController

**Repo:** `auth-service-helper`
**Spec section:** §7 controller responsibilities (3)+(5); §8 "consumeHandoffToken internally"; §17 Q3
**Depends on:** D2b (webhook controller skeleton), B6 (IntentRegistry::hydrate + schemaPathFor), C3 (HandoffTokenClient)

## Goal

Extend `InboundShareWebhookController::receive()` so that after persistence it ALSO validates the payload against the intent's JSON-schema and (on success) dispatches `InboundShareReceived` with a typed `SharePayload`. The persisted row's `processing_status` advances through `validated → dispatched` (or terminates at `rejected_schema`). Add the SECOND inbound controller — `InboundHandoffExchangeController` — that the Next helper's `/auth/handoff` route POSTs to so Next never talks to auth-service directly (spec §17 Q3 resolution).

## Files

- **Modify:** `composer.json` (add `justinrainbow/json-schema`)
- **Modify:** `src/Sharing/Inbox/Http/Controllers/InboundShareWebhookController.php` (extend `receive`)
- **Create:** `src/Sharing/Inbox/Http/Controllers/InboundHandoffExchangeController.php`
- **Create:** `src/Sharing/Inbox/Queries/Sharing.php` (with `::lastInboundFor()` static helper)
- **Test:** `tests/Feature/Sharing/Inbox/InboundShareWebhookValidationTest.php`
- **Test:** `tests/Feature/Sharing/Inbox/InboundHandoffExchangeControllerTest.php`

## Steps

### Step 1 — Failing tests

```php
<?php
// tests/Feature/Sharing/Inbox/InboundShareWebhookValidationTest.php

namespace Tests\Feature\Sharing\Inbox;

use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use AuthService\Helper\Sharing\Inbox\Events\InboundShareReceived;
use AuthService\Helper\Sharing\Inbox\Http\Controllers\InboundShareWebhookController;
use AuthService\Helper\Sharing\Inbox\InboundShareMessage;
use AuthService\Helper\Sharing\Inbox\Queries\Sharing;
use AuthService\Helper\Sharing\Intents\Contracts\SharePayload;
use AuthService\Helper\Sharing\Intents\IntentRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;

class InboundShareWebhookValidationTest extends TestCase
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
        $schemaPath = __DIR__ . '/fixtures/test-intent.schema.json';
        if (!file_exists(dirname($schemaPath))) {
            mkdir(dirname($schemaPath), 0777, true);
        }
        file_put_contents($schemaPath, json_encode([
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'required' => ['order_id'],
            'properties' => ['order_id' => ['type' => 'string']],
            'additionalProperties' => true,
        ]));

        /** @var IntentRegistry $reg */
        $reg = $this->app->make(IntentRegistry::class);
        $reg->register('test.purchase', TestPurchasePayload::class, schemaPath: $schemaPath);
    }

    public function test_valid_payload_dispatches_event_and_marks_dispatched(): void
    {
        Event::fake([InboundShareReceived::class]);
        $env = $this->fakeEnv(['order_id' => 'ord_123']);

        $resp = $this->controller()->receive($this->request($env));
        $this->assertEquals(202, $resp->getStatusCode());

        $row = InboundShareMessage::where('message_id', $env->messageId)->firstOrFail();
        $this->assertEquals('dispatched', $row->processing_status);
        $this->assertNotNull($row->dispatched_at);

        Event::assertDispatched(
            InboundShareReceived::class,
            fn (InboundShareReceived $e) =>
                $e->shareId === $env->correlationId
                && $e->intent === 'test.purchase'
                && $e->payload instanceof TestPurchasePayload
                && $e->payload->orderId === 'ord_123'
        );
    }

    public function test_invalid_payload_returns_422_and_marks_rejected_schema(): void
    {
        Event::fake([InboundShareReceived::class]);
        $env = $this->fakeEnv(['wrong_field' => 'x']); // missing required order_id

        $resp = $this->controller()->receive($this->request($env));
        $this->assertEquals(422, $resp->getStatusCode());

        $row = InboundShareMessage::where('message_id', $env->messageId)->firstOrFail();
        $this->assertEquals('rejected_schema', $row->processing_status);
        $this->assertNotNull($row->processing_error);

        Event::assertNotDispatched(InboundShareReceived::class);
    }

    public function test_last_inbound_for_query_helper(): void
    {
        $env1 = $this->fakeEnv(['order_id' => 'a']);
        $this->controller()->receive($this->request($env1));
        usleep(10_000);
        $env2 = ShareEnvelope::fromArray(array_merge($env1->toArray(), [
            'message_id' => 'msg_NEW',
            'idempotency_key' => 'different-key',
            'payload' => ['order_id' => 'b'],
        ]));
        $this->controller()->receive($this->request($env2));

        $latest = Sharing::lastInboundFor($env1->correlationId);
        $this->assertNotNull($latest);
        $this->assertEquals('msg_NEW', $latest->message_id);
    }

    private function controller(): InboundShareWebhookController
    {
        return $this->app->make(InboundShareWebhookController::class);
    }

    private function fakeEnv(array $payload): ShareEnvelope
    {
        return ShareEnvelope::fromArray([
            'envelope_version' => '1',
            'message_id' => 'msg_' . bin2hex(random_bytes(8)),
            'correlation_id' => '00000000-0000-0000-0000-000000000010',
            'intent' => 'test.purchase',
            'intent_version' => '1.0',
            'source_service_id' => '00000000-0000-0000-0000-000000000001',
            'target_service_id' => '00000000-0000-0000-0000-000000000002',
            'user_id' => '00000000-0000-0000-0000-000000000003',
            'idempotency_key' => 'k_' . bin2hex(random_bytes(6)),
            'issued_at' => '2026-05-27T10:00:00Z',
            'payload' => $payload,
        ]);
    }

    private function request(ShareEnvelope $env): Request
    {
        $req = Request::create('/inbound/user-share', 'POST', content: $env->toCanonicalJson());
        $req->attributes->set('source_service_id', '00000000-0000-0000-0000-000000000001');
        $req->attributes->set('peer_slug', 'studendly');
        $req->headers->set('X-Signature', 't=' . time() . ',v1=' . str_repeat('a', 64));
        return $req;
    }
}

class TestPurchasePayload implements SharePayload
{
    public function __construct(public readonly string $orderId) {}
    public static function fromArray(array $raw): self { return new self($raw['order_id']); }
    public function toArray(): array { return ['order_id' => $this->orderId]; }
    public static function intentSlug(): string { return 'test.purchase'; }
    public static function intentVersion(): string { return '1.0'; }
}
```

```php
<?php
// tests/Feature/Sharing/Inbox/InboundHandoffExchangeControllerTest.php

namespace Tests\Feature\Sharing\Inbox;

use AuthService\Helper\Sharing\HandoffTokenClient;
use AuthService\Helper\Sharing\Inbox\Events\InboundHandoffCompleted;
use AuthService\Helper\Sharing\Inbox\Http\Controllers\InboundHandoffExchangeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Mockery;
use Orchestra\Testbench\TestCase;

class InboundHandoffExchangeControllerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\AuthService\Helper\AuthServiceHelperServiceProvider::class];
    }

    public function test_exchange_returns_user_session_token_share_id_next_path(): void
    {
        Event::fake([InboundHandoffCompleted::class]);

        $mockClient = Mockery::mock(HandoffTokenClient::class);
        $mockClient->shouldReceive('exchange')->once()->with('tok_raw_abc')->andReturn([
            'user' => ['uuid' => 'u-uuid', 'name' => 'Jane', 'email' => 'jane@x.com'],
            'session_token' => 'sess_xyz',
            'share_id' => 'share_99',
            'next_path' => '/cases/abc',
        ]);
        $this->app->instance(HandoffTokenClient::class, $mockClient);

        $req = Request::create('/inbound/handoff/exchange', 'POST', ['token' => 'tok_raw_abc']);
        $resp = $this->app->make(InboundHandoffExchangeController::class)->exchange($req);

        $this->assertEquals(200, $resp->getStatusCode());
        $body = $resp->getData(true);
        $this->assertEquals('u-uuid', $body['user']['uuid']);
        $this->assertEquals('sess_xyz', $body['session_token']);
        $this->assertEquals('share_99', $body['share_id']);
        $this->assertEquals('/cases/abc', $body['next_path']);

        Event::assertDispatched(
            InboundHandoffCompleted::class,
            fn (InboundHandoffCompleted $e) =>
                $e->shareId === 'share_99' && $e->userId === 'u-uuid' && $e->nextPath === '/cases/abc'
        );
    }

    public function test_missing_token_returns_400(): void
    {
        $req = Request::create('/inbound/handoff/exchange', 'POST', []);
        $resp = $this->app->make(InboundHandoffExchangeController::class)->exchange($req);
        $this->assertEquals(400, $resp->getStatusCode());
    }
}
```

### Step 2 — Run, expect failure

```bash
vendor/bin/pest tests/Feature/Sharing/Inbox/InboundShareWebhookValidationTest.php \
                tests/Feature/Sharing/Inbox/InboundHandoffExchangeControllerTest.php
```

### Step 3 — Add the JSON-schema dependency

Edit `composer.json` to add (under `require`):

```json
"justinrainbow/json-schema": "^5.2 || ^6.0"
```

Then:

```bash
composer require justinrainbow/json-schema --no-update
composer update justinrainbow/json-schema
```

### Step 4 — Extend the webhook controller; add the second controller; add the query helper

Edit `src/Sharing/Inbox/Http/Controllers/InboundShareWebhookController.php` — after the existing `create()` block (the row insert), replace the trailing `return new JsonResponse(...)` with the validation+dispatch flow:

```php
// (after the InboundShareMessage::create(...) block, before returning 202)

return $this->validateAndDispatch($row);
```

…and add these methods to the same class:

```php
use AuthService\Helper\Sharing\Inbox\Events\InboundShareReceived;
use AuthService\Helper\Sharing\Intents\Exceptions\UnknownIntentException;
use AuthService\Helper\Sharing\Intents\IntentRegistry;
use Illuminate\Support\Facades\Event;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;

// add to constructor:
//   public function __construct(
//       protected IdempotencyGuard $idempotency,
//       protected IntentRegistry $intents,
//   ) {}

protected function validateAndDispatch(InboundShareMessage $row): JsonResponse
{
    // 1. Schema validation (only if intent registered a schema path)
    try {
        $schemaPath = $this->intents->schemaPathFor($row->intent);
    } catch (UnknownIntentException $e) {
        return $this->markRejectedSchema($row, "Unknown intent: {$row->intent}");
    }

    if ($schemaPath !== null) {
        $validator = new Validator();
        $payloadObject = json_decode(json_encode($row->payload)); // stdClass for the validator
        $validator->validate(
            $payloadObject,
            (object) ['$ref' => 'file://' . str_replace('\\', '/', $schemaPath)],
            Constraint::CHECK_MODE_NORMAL,
        );

        if (!$validator->isValid()) {
            $errors = collect($validator->getErrors())
                ->map(fn ($e) => "{$e['property']}: {$e['message']}")
                ->implode('; ');
            return $this->markRejectedSchema($row, $errors);
        }
    }

    $row->processing_status = 'validated';
    $row->save();

    // 2. Hydrate typed payload + dispatch event
    $typedPayload = $this->intents->hydrate($row->intent, $row->payload);

    Event::dispatch(new InboundShareReceived(
        shareId: $row->correlation_id,
        intent: $row->intent,
        intentVersion: $row->intent_version,
        userId: $row->user_id,
        sourceServiceId: $row->source_service_id,
        payload: $typedPayload,
        messageId: $row->message_id,
        correlationId: $row->correlation_id,
        metadata: [
            'received_at' => $row->received_at?->toIso8601String(),
            'headers' => $row->headers ?? [],
        ],
    ));

    $row->processing_status = 'dispatched';
    $row->dispatched_at = now();
    $row->save();

    return new JsonResponse([
        'message_id' => $row->message_id,
        'status' => 'dispatched',
    ], 202);
}

protected function markRejectedSchema(InboundShareMessage $row, string $error): JsonResponse
{
    $row->processing_status = 'rejected_schema';
    $row->processing_error = $error;
    $row->save();

    return new JsonResponse([
        'error' => 'schema_validation_failed',
        'message_id' => $row->message_id,
        'detail' => $error,
    ], 422);
}
```

Create the handoff-exchange controller:

```php
<?php
// src/Sharing/Inbox/Http/Controllers/InboundHandoffExchangeController.php

namespace AuthService\Helper\Sharing\Inbox\Http\Controllers;

use AuthService\Helper\Sharing\HandoffTokenClient;
use AuthService\Helper\Sharing\Inbox\Events\InboundHandoffCompleted;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

class InboundHandoffExchangeController
{
    public function __construct(
        protected HandoffTokenClient $handoffTokens,
    ) {}

    public function exchange(Request $request): JsonResponse
    {
        $token = (string) $request->input('token', '');
        if ($token === '') {
            return new JsonResponse([
                'error' => 'missing_token',
                'message' => 'POST body must include a non-empty "token" field',
            ], 400);
        }

        try {
            $result = $this->handoffTokens->exchange($token);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => 'exchange_failed',
                'message' => $e->getMessage(),
            ], 400);
        }

        Event::dispatch(new InboundHandoffCompleted(
            shareId: (string) ($result['share_id'] ?? ''),
            userId: (string) ($result['user']['uuid'] ?? ''),
            targetServiceId: (string) config('authservice.service_id', ''),
            nextPath: (string) ($result['next_path'] ?? '/'),
            consumedAt: now()->toIso8601String(),
        ));

        return new JsonResponse([
            'user' => $result['user'] ?? null,
            'session_token' => $result['session_token'] ?? null,
            'share_id' => $result['share_id'] ?? null,
            'next_path' => $result['next_path'] ?? '/',
        ], 200);
    }
}
```

Create the query helper:

```php
<?php
// src/Sharing/Inbox/Queries/Sharing.php

namespace AuthService\Helper\Sharing\Inbox\Queries;

use AuthService\Helper\Sharing\Inbox\InboundShareMessage;

class Sharing
{
    public static function lastInboundFor(string $shareId): ?InboundShareMessage
    {
        return InboundShareMessage::query()
            ->where('correlation_id', $shareId)
            ->orderByDesc('received_at')
            ->first();
    }
}
```

### Step 5 — Run + commit

```bash
vendor/bin/pest tests/Feature/Sharing/Inbox/InboundShareWebhookValidationTest.php \
                tests/Feature/Sharing/Inbox/InboundHandoffExchangeControllerTest.php
# Expected: 5 passed total (3 + 2).

git add composer.json composer.lock \
        src/Sharing/Inbox/Http/Controllers/InboundShareWebhookController.php \
        src/Sharing/Inbox/Http/Controllers/InboundHandoffExchangeController.php \
        src/Sharing/Inbox/Queries/Sharing.php \
        tests/Feature/Sharing/Inbox/
git commit -m "feat(sharing): phase D2c — schema validation + event dispatch + handoff exchange

Webhook controller now advances persisted rows through
received → validated → dispatched, validating payload with
justinrainbow/json-schema against IntentRegistry::schemaPathFor and
dispatching InboundShareReceived with a typed SharePayload via
IntentRegistry::hydrate. Schema failure → 422 + status=rejected_schema.

Adds InboundHandoffExchangeController that wraps HandoffTokenClient so
the Next helper /auth/handoff route never has to call auth-service
directly (spec §17 Q3 — generic mountable controller). Fires
InboundHandoffCompleted on success.

Adds Sharing::lastInboundFor(shareId) query helper for product code.

Phase: D2c of docs/plans/InterProductCommunication-2026-05-27/"
```

## Verification checklist

- [ ] Valid payload → row marked `dispatched`, event fired with hydrated `SharePayload`
- [ ] Invalid payload → 422, row marked `rejected_schema` with `processing_error` populated, no event
- [ ] Unknown intent → 422 `rejected_schema` (not 500)
- [ ] `Sharing::lastInboundFor()` returns most-recent by `received_at`
- [ ] Handoff exchange controller missing token → 400; otherwise wraps `HandoffTokenClient::exchange` and emits `InboundHandoffCompleted`
