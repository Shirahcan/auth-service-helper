# Phase G1 — PHP 2-app in-process round-trip harness

**Repo:** `auth-service-helper`
**Spec section:** §14 (PHP integration row)
**Depends on:** B (all), C (all), D (all), E (all)

## Goal

Prove the full source → destination flow in a single PHPUnit process. One Testbench-booted Laravel app plays BOTH roles (source enqueues, destination receives) — the wire layer in between is short-circuited with `Http::fake()` for the auth-service hops and an in-process `$this->postJson()` for the destination webhook. Two tests:

1. **Payload round-trip:** `Sharing::sendPayload()` → outbox row → `DispatchOutboundShareJob` runs → POSTs signed envelope at destination's mounted webhook → `InboundShareReceived` event fires with the typed payload.
2. **Handoff round-trip:** `Sharing::mintHandoffToken()` (auth-service mint faked) → redirect URL inspected → `consumeHandoffToken`-equivalent PHP call on destination → auth-service exchange faked → `InboundHandoffCompleted` event fires.

## Files

- **Create:** `tests/Integration/Sharing/EndToEndRoundTripTest.php`
- **Create:** `tests/Integration/Sharing/Fixtures/TestServicePurchaseHandler.php`
- **Modify:** `composer.json` (add `orchestra/testbench: ^9.0` to `require-dev`, add `Tests\\Integration\\` to autoload-dev psr-4)

## Steps

### Step 1 — Add Testbench + adjust TestCase + autoload-dev

In `composer.json` make TWO edits, then run `composer update orchestra/testbench`:

**Edit 1 — `require-dev` section:**

```json
"require-dev": {
    "orchestra/testbench": "^9.0",
    // …existing entries…
}
```

**Edit 2 — `autoload-dev.psr-4` section** (CRITICAL — without it the integration test's fixture class won't autoload and the test will fatal on class-not-found):

```json
"autoload-dev": {
    "psr-4": {
        "Tests\\": "tests/",
        "Tests\\Integration\\": "tests/Integration/"
    }
}
```

(If `autoload-dev` already exists, merge the `Tests\\Integration\\` mapping in; don't replace the whole block.)

Run `composer dump-autoload` after editing.

The existing `Tests\\TestCase` extends `PHPUnit\Framework\TestCase`; integration tests need a Laravel app so create a sibling base class in the test file itself (Testbench's `Orchestra\Testbench\TestCase`).

### Step 2 — Write the test fixture handler

```php
<?php
// tests/Integration/Sharing/Fixtures/TestServicePurchaseHandler.php

namespace Tests\Integration\Sharing\Fixtures;

use AuthService\Helper\Sharing\Inbox\Events\InboundShareReceived;

final class TestServicePurchaseHandler
{
    /** @var array<int,InboundShareReceived> */
    public static array $received = [];

    public function handle(InboundShareReceived $event): void
    {
        if ($event->intent !== 'service_purchase') {
            return;
        }
        self::$received[] = $event;
    }

    public static function reset(): void
    {
        self::$received = [];
    }
}
```

### Step 3 — Write the round-trip test

```php
<?php
// tests/Integration/Sharing/EndToEndRoundTripTest.php

namespace Tests\Integration\Sharing;

use AuthService\Helper\AuthServiceHelperServiceProvider;
use AuthService\Helper\Sharing\Facades\Sharing;
use AuthService\Helper\Sharing\Inbox\Events\InboundHandoffCompleted;
use AuthService\Helper\Sharing\Inbox\Events\InboundShareReceived;
use AuthService\Helper\Sharing\Intents\Builtin\ServicePurchasePayload;
use AuthService\Helper\Sharing\Outbox\DispatchOutboundShareJob;
use AuthService\Helper\Sharing\Outbox\OutboundShareMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use Tests\Integration\Sharing\Fixtures\TestServicePurchaseHandler;

class EndToEndRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [AuthServiceHelperServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Sharing config: same app plays both sides, so source secret == dest secret.
        $app['config']->set('authservice.sharing', [
            'webhook_path' => '/api/v1/inbound/user-share',
            'handoff_exchange_path' => '/api/v1/inbound/handoff/exchange',
            'trust_key' => 'test-trust-key',
            'signing_secret_current' => 'test-signing-secret',
            'signing_secret_previous' => null,
            'idempotency_retention_days' => 30,
            'source_service_id' => '11111111-1111-1111-1111-111111111111',
            'target_service_id' => '22222222-2222-2222-2222-222222222222',
            'auth_service_base_url' => 'https://auth.test',
            'service_api_key' => 'svc-key',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        TestServicePurchaseHandler::reset();
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
    }

    public function test_payload_round_trip_dispatches_inbound_event_on_destination(): void
    {
        Event::listen(InboundShareReceived::class, [TestServicePurchaseHandler::class, 'handle']);

        $shareId = '33333333-3333-3333-3333-333333333333';
        $userId = '44444444-4444-4444-4444-444444444444';

        // Stub the share-exists lookup (UserShareClient resolves shareId → target_service_id).
        Http::fake([
            'https://auth.test/api/v1/user-shares/'.$shareId => Http::response([
                'data' => [
                    'id' => $shareId,
                    'user_id' => $userId,
                    'target_service_id' => '22222222-2222-2222-2222-222222222222',
                    'status' => 'active',
                ],
            ], 200),
        ]);

        // Queue::fake() lets us inspect what jobs got pushed, then we run them synchronously.
        Queue::fake();

        $outbound = Sharing::sendPayload(
            shareId: $shareId,
            intent: 'service_purchase',
            payload: new ServicePurchasePayload(
                order_id: 'studendly:order:88421',
                service_slug: 'study_visa',
                amount_cents: 40000,
                currency: 'CAD',
                purchased_at: Carbon::parse('2026-05-27T10:00:00Z'),
                items: [],
            ),
            idempotencyKey: 'studendly:order:88421',
        );

        $this->assertInstanceOf(OutboundShareMessage::class, $outbound);
        $this->assertSame('queued', $outbound->status);
        Queue::assertPushed(DispatchOutboundShareJob::class);

        // Now route the outbound HTTP call back into this same app via in-process postJson.
        // Replace the helper's outbound HTTP::post() with a closure that calls $this->postJson.
        $self = $this;
        Http::fake(function ($request) use ($self) {
            if (str_contains($request->url(), '/api/v1/inbound/user-share')) {
                $response = $self->postJson(
                    '/api/v1/inbound/user-share',
                    $request->data(),
                    $request->headers() ? array_map(fn($v) => $v[0] ?? '', $request->headers()) : [],
                );
                return Http::response($response->getContent(), $response->getStatusCode());
            }
            return Http::response([], 404);
        });

        // Execute the queued job synchronously.
        (new DispatchOutboundShareJob($outbound->id))->handle();

        $this->assertCount(1, TestServicePurchaseHandler::$received);
        $event = TestServicePurchaseHandler::$received[0];
        $this->assertSame('service_purchase', $event->intent);
        $this->assertSame($userId, $event->userId);
        $this->assertInstanceOf(ServicePurchasePayload::class, $event->payload);
        $this->assertSame('studendly:order:88421', $event->payload->order_id);
        $this->assertSame(40000, $event->payload->amount_cents);

        // And the outbox row moved to delivered.
        $this->assertDatabaseHas('outbound_share_messages', [
            'id' => $outbound->id,
            'status' => 'delivered',
        ]);
    }

    public function test_handoff_round_trip_fires_inbound_handoff_completed_event(): void
    {
        Event::fake([InboundHandoffCompleted::class]);

        $shareId = '55555555-5555-5555-5555-555555555555';
        $token = 'tok_'.str_repeat('a', 40);
        $userId = '66666666-6666-6666-6666-666666666666';

        // Source: Sharing::mintHandoffToken() → auth-service /handoff-tokens
        Http::fake([
            'https://auth.test/api/v1/auth/handoff-tokens' => Http::response([
                'token' => $token,
                'redirect_url' => 'https://destination.test/auth/handoff?token='.$token.'&next=/cases/abc',
                'expires_at' => Carbon::now()->addMinute()->toIso8601String(),
            ], 200),
            'https://auth.test/api/v1/auth/handoff-tokens/'.$token.'/exchange' => Http::response([
                'user' => [
                    'uuid' => $userId,
                    'name' => 'Jane Student',
                    'email' => 'jane@example.test',
                ],
                'session_token' => 'sess_'.str_repeat('b', 40),
                'share_id' => $shareId,
                'next_path' => '/cases/abc',
            ], 200),
        ]);

        $minted = Sharing::mintHandoffToken(shareId: $shareId, nextPath: '/cases/abc');
        $this->assertStringContainsString($token, $minted->redirectUrl);

        // Destination: simulate the in-process exchange controller call.
        $response = $this->postJson('/api/v1/inbound/handoff/exchange', ['token' => $token]);
        $response->assertOk();
        $response->assertJsonStructure(['user' => ['uuid', 'name', 'email'], 'session_token', 'share_id', 'next_path']);

        Event::assertDispatched(InboundHandoffCompleted::class, function ($e) use ($shareId, $userId) {
            return $e->shareId === $shareId && $e->userId === $userId;
        });
    }
}
```

### Step 4 — Run + commit

```bash
cd C:\Users\benpl\Documents\GitHub\auth-service-helper
composer update orchestra/testbench --with-dependencies
vendor/bin/phpunit --filter=EndToEndRoundTripTest
# Expected: 2 passed.

git add composer.json composer.lock tests/Integration/Sharing/
git commit -m "feat(sharing): phase G1 — PHP in-process round-trip integration test

Orchestra Testbench app plays both source + destination. Http::fake
short-circuits the auth-service hops; the destination webhook is hit
in-process via \$this->postJson. Two tests cover (a) payload outbox →
inbound event and (b) handoff token mint → exchange → event.

Phase: G1 of docs/plans/InterProductCommunication-2026-05-27/"
```

## Notes for executor

- If `Queue::fake()` + in-process post combination races, simplify by skipping Queue::fake and just instantiating the job directly with `new DispatchOutboundShareJob($outbound->id)->handle()` — the outbox row is the source of truth.
- The `Http::fake` callback pattern (Step 3 second `Http::fake`) is the supported way to route fake responses through arbitrary code per Laravel 11 docs.
- If `defineEnvironment` cannot resolve `authservice.sharing` because Phase B1 used a different config key, adjust to the actual key — DO NOT invent one here.
