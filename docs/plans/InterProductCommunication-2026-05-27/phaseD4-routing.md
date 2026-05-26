# Phase D4 — ServiceProvider routing + config publishing

**Repo:** `auth-service-helper`
**Spec section:** §7 "Destination-Side API" (config) + §8 "consumeHandoffToken internally"
**Depends on:** D1 (migration), D2a (middleware), D2b (controller), D2c (handoff controller)

## Goal

Mount BOTH inbound routes from `SharingServiceProvider::boot()` so consumer products get them for free with zero `routes/api.php` edits, register the migration via `loadMigrationsFrom`, register middleware aliases, and publish a `config/authservice-sharing.php` skeleton. Two routes:

| Route | Middleware | Purpose |
|---|---|---|
| `POST /api/v1/inbound/user-share` | `share-envelope.verify` | Source-product webhook (P2P envelope) |
| `POST /api/v1/inbound/handoff/exchange` | `share-helper.internal` | Called by Next `/auth/handoff` route; wraps `HandoffTokenClient::exchange` |

The internal middleware is a thin shared-secret check (`X-Internal-Token` matches `config('authservice-sharing.internal_token')`) so only the same-host Next backend can call the exchange controller.

## Files

- **Modify:** `src/Sharing/SharingServiceProvider.php` (boot — register routes + migrations + middleware + config publish)
- **Create:** `config/authservice-sharing.php` (skeleton for vendor:publish)
- **Create:** `src/Sharing/Inbox/Http/Middleware/VerifyInternalToken.php`
- **Test:** `tests/Feature/Sharing/Inbox/RoutingTest.php`

## Steps

### Step 1 — Failing routing test

```php
<?php
// tests/Feature/Sharing/Inbox/RoutingTest.php

namespace Tests\Feature\Sharing\Inbox;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

class RoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [\AuthService\Helper\AuthServiceHelperServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('authservice-sharing.internal_token', 'internal_xxx');
        $app['config']->set('authservice-sharing.peers', []);
    }

    public function test_inbound_user_share_route_is_registered(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/v1/inbound/user-share' && in_array('POST', $r->methods(), true)
        );
        $this->assertNotNull($route, 'POST /api/v1/inbound/user-share not registered');

        $middleware = $route->gatherMiddleware();
        $this->assertTrue(
            collect($middleware)->contains(fn ($m) => str_contains($m, 'VerifyShareEnvelopeSignature'))
            || in_array('share-envelope.verify', $middleware, true),
            'user-share route missing signature middleware'
        );
    }

    public function test_inbound_handoff_exchange_route_is_registered(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/v1/inbound/handoff/exchange' && in_array('POST', $r->methods(), true)
        );
        $this->assertNotNull($route, 'POST /api/v1/inbound/handoff/exchange not registered');

        $middleware = $route->gatherMiddleware();
        $this->assertTrue(
            collect($middleware)->contains(fn ($m) => str_contains($m, 'VerifyInternalToken'))
            || in_array('share-helper.internal', $middleware, true),
            'handoff/exchange route missing internal-token middleware'
        );
    }

    public function test_internal_middleware_rejects_missing_token(): void
    {
        $resp = $this->postJson('/api/v1/inbound/handoff/exchange', ['token' => 'tok']);
        $this->assertEquals(401, $resp->getStatusCode());
    }

    public function test_internal_middleware_accepts_matching_token(): void
    {
        // Provides a wrong handoff token so the controller itself errors with 400.
        // What we're asserting is that we GOT to the controller (i.e. middleware passed).
        $this->app->instance(
            \AuthService\Helper\Sharing\Client\HandoffTokenClient::class,
            new class {
                public function exchange(string $t): array
                {
                    return [
                        'user' => ['uuid' => 'u-1', 'name' => 'X'],
                        'session_token' => 's',
                        'share_id' => 'sh',
                        'next_path' => '/',
                    ];
                }
            },
        );

        $resp = $this->postJson(
            '/api/v1/inbound/handoff/exchange',
            ['token' => 'tok'],
            ['X-Internal-Token' => 'internal_xxx'],
        );
        $this->assertEquals(200, $resp->getStatusCode());
    }

    public function test_config_publish_tag_is_registered(): void
    {
        $groups = \Illuminate\Support\ServiceProvider::publishableGroups();
        $this->assertContains('auth-service-helper-sharing-config', $groups);
    }
}
```

### Step 2 — Run, expect failure

```bash
vendor/bin/pest tests/Feature/Sharing/Inbox/RoutingTest.php
```

Expected: routes not registered, publish tag absent.

### Step 3 — Implement the internal-token middleware

```php
<?php
// src/Sharing/Inbox/Http/Middleware/VerifyInternalToken.php

namespace AuthService\Helper\Sharing\Inbox\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lightweight shared-secret check so only same-host Next backend code
 * can call /api/v1/inbound/handoff/exchange. Mirrors the pattern the
 * spec §8 calls out: "consumeHandoffToken POSTs to destination's PHP
 * backend /api/v1/inbound/handoff/exchange (helper-provided controller)".
 */
class VerifyInternalToken
{
    public function handle(Request $request, Closure $next): mixed
    {
        $expected = (string) config('authservice-sharing.internal_token', '');
        $provided = (string) $request->header('X-Internal-Token', '');

        if ($expected === '' || !hash_equals($expected, $provided)) {
            return new JsonResponse([
                'error' => 'unauthorized',
                'message' => 'Valid X-Internal-Token header required',
            ], 401);
        }

        return $next($request);
    }
}
```

### Step 4 — Implement the config skeleton

```php
<?php
// config/authservice-sharing.php

return [
    /*
    |--------------------------------------------------------------------------
    | Inbound webhook path
    |--------------------------------------------------------------------------
    | The path on which the InboundShareWebhookController is mounted. Change
    | only if you need to integrate with an existing routing convention.
    */
    'webhook_path' => '/api/v1/inbound/user-share',

    /*
    |--------------------------------------------------------------------------
    | Handoff exchange path
    |--------------------------------------------------------------------------
    | The path on which InboundHandoffExchangeController is mounted. The Next
    | helper's /auth/handoff route POSTs here.
    */
    'handoff_exchange_path' => '/api/v1/inbound/handoff/exchange',

    /*
    |--------------------------------------------------------------------------
    | Internal token (shared between Next backend and PHP backend)
    |--------------------------------------------------------------------------
    | Must be set per environment via SHARING_INTERNAL_TOKEN env var. The Next
    | helper sends this in X-Internal-Token when calling the handoff exchange.
    */
    'internal_token' => env('SHARING_INTERNAL_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Replay window (seconds)
    |--------------------------------------------------------------------------
    | Allowable clock skew between source and destination for X-Signature
    | timestamps. Spec default: 300s (±5min).
    */
    'replay_window_seconds' => 300,

    /*
    |--------------------------------------------------------------------------
    | Idempotency retention (days)
    |--------------------------------------------------------------------------
    | How long inbound_share_messages rows are retained for idempotency
    | de-duplication. Cleanup is operator-driven (see Phase H4).
    */
    'idempotency_retention_days' => 30,

    /*
    |--------------------------------------------------------------------------
    | Peer registry
    |--------------------------------------------------------------------------
    | Each entry is a trusted source product whose webhooks this destination
    | accepts. The middleware matches by `trust_key`. Add one per consumer
    | product. previous_secret enables zero-downtime HMAC rotation.
    |
    | 'studendly' => [
    |     'source_service_id' => env('STUDENDLY_SERVICE_ID'),
    |     'trust_key'         => env('STUDENDLY_TRUST_KEY'),
    |     'current_secret'    => env('STUDENDLY_HMAC_SECRET'),
    |     'previous_secret'   => env('STUDENDLY_HMAC_SECRET_PREV'),
    | ],
    */
    'peers' => [],
];
```

### Step 5 — Wire SharingServiceProvider::boot()

Replace the body of `src/Sharing/SharingServiceProvider.php` `boot()` (and extend `register()` for the config merge):

```php
public function register(): void
{
    $this->mergeConfigFrom(__DIR__ . '/../../config/authservice-sharing.php', 'authservice-sharing');

    // (bindings from B6/D2b stay as-is)
}

public function boot(): void
{
    // Migrations
    $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

    // Middleware aliases
    /** @var \Illuminate\Routing\Router $router */
    $router = $this->app['router'];
    $router->aliasMiddleware('share-envelope.verify', \AuthService\Helper\Sharing\Inbox\Http\Middleware\VerifyShareEnvelopeSignature::class);
    $router->aliasMiddleware('share-helper.internal', \AuthService\Helper\Sharing\Inbox\Http\Middleware\VerifyInternalToken::class);

    // Routes
    $router->group([
        'prefix' => '',
        'middleware' => ['api'],
    ], function ($router) {
        $router->post(
            ltrim((string) config('authservice-sharing.webhook_path'), '/'),
            [\AuthService\Helper\Sharing\Inbox\Http\Controllers\InboundShareWebhookController::class, 'receive'],
        )->middleware('share-envelope.verify');

        $router->post(
            ltrim((string) config('authservice-sharing.handoff_exchange_path'), '/'),
            [\AuthService\Helper\Sharing\Inbox\Http\Controllers\InboundHandoffExchangeController::class, 'exchange'],
        )->middleware('share-helper.internal');
    });

    // Config publish
    $this->publishes([
        __DIR__ . '/../../config/authservice-sharing.php' => config_path('authservice-sharing.php'),
    ], 'auth-service-helper-sharing-config');
}
```

### Step 6 — Run + commit

```bash
vendor/bin/pest tests/Feature/Sharing/Inbox/RoutingTest.php
# Expected: 5 passed.

git add src/Sharing/SharingServiceProvider.php \
        src/Sharing/Inbox/Http/Middleware/VerifyInternalToken.php \
        config/authservice-sharing.php \
        tests/Feature/Sharing/Inbox/RoutingTest.php
git commit -m "feat(sharing): phase D4 — ServiceProvider routing + config

Mounts BOTH inbound routes from SharingServiceProvider::boot() with zero
routes/api.php edits required by consumer products:

  POST /api/v1/inbound/user-share        (share-envelope.verify)
  POST /api/v1/inbound/handoff/exchange  (share-helper.internal)

Adds VerifyInternalToken middleware (hash_equals against
config('authservice-sharing.internal_token')) so only the same-host Next
backend can call the handoff exchange controller. Publishes
config/authservice-sharing.php skeleton with documented peer-registry
shape under the 'auth-service-helper-sharing-config' tag.

Phase: D4 of docs/plans/InterProductCommunication-2026-05-27/"
```

## Verification checklist

- [ ] `php artisan route:list | grep inbound` shows BOTH routes registered
- [ ] `php artisan vendor:publish --tag=auth-service-helper-sharing-config` writes `config/authservice-sharing.php`
- [ ] Internal middleware uses `hash_equals` (timing-safe), not `===`
- [ ] Routes use the `api` middleware group (so they get JSON exception handling / throttling defaults), not `web`
- [ ] Phase D is now end-to-end testable via Phase G1 round-trip harness
