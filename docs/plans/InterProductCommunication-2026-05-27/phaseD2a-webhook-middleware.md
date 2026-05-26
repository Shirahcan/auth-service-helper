# Phase D2a — Webhook signature + trust-key middleware

**Repo:** `auth-service-helper`
**Spec section:** §5 "Required Headers" + §7 controller responsibility (1)
**Depends on:** B4 (EnvelopeVerifier), D1 (table exists for context)

## Goal

Single middleware that runs first on the inbound webhook: identifies WHICH peer is calling (via `X-Trust-Key`), looks up that peer's HMAC current+previous secrets from `config('authservice.sharing.peers')`, and uses `EnvelopeVerifier` to validate the `X-Signature` header against the raw request body. On success attaches `source_service_id` + `peer_slug` to the request for the controller. On failure: 401 (bad trust key) / 400 (bad/replay signature).

## Files

- **Create:** `src/Sharing/Inbox/Http/Middleware/VerifyShareEnvelopeSignature.php`
- **Test:** `tests/Feature/Sharing/Inbox/VerifyShareEnvelopeSignatureTest.php`

## Steps

### Step 1 — Write the failing middleware test

```php
<?php
// tests/Feature/Sharing/Inbox/VerifyShareEnvelopeSignatureTest.php

namespace Tests\Feature\Sharing\Inbox;

use AuthService\Helper\Sharing\Envelope\EnvelopeSigner;
use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use AuthService\Helper\Sharing\Inbox\Http\Middleware\VerifyShareEnvelopeSignature;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;

class VerifyShareEnvelopeSignatureTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\AuthService\Helper\AuthServiceHelperServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('authservice.sharing.peers', [
            'studendly' => [
                'source_service_id' => '00000000-0000-0000-0000-000000000001',
                'trust_key' => 'trust_studendly_xxx',
                'current_secret' => 'sec_current',
                'previous_secret' => null,
            ],
        ]);
    }

    public function test_passes_with_valid_trust_key_and_signature(): void
    {
        $env = $this->fakeEnv();
        $body = $env->toCanonicalJson();
        $header = (new EnvelopeSigner('sec_current'))->sign($env);

        $req = Request::create('/inbound/user-share', 'POST', [], [], [], [
            'HTTP_X_TRUST_KEY' => 'trust_studendly_xxx',
            'HTTP_X_SIGNATURE' => $header,
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        $called = false;
        $next = function (Request $r) use (&$called) {
            $called = true;
            $this->assertEquals('00000000-0000-0000-0000-000000000001', $r->attributes->get('source_service_id'));
            $this->assertEquals('studendly', $r->attributes->get('peer_slug'));
            return response('ok', 200);
        };

        $resp = (new VerifyShareEnvelopeSignature())->handle($req, $next);
        $this->assertTrue($called);
        $this->assertEquals(200, $resp->getStatusCode());
    }

    public function test_unknown_trust_key_returns_401(): void
    {
        $req = Request::create('/inbound/user-share', 'POST', [], [], [], [
            'HTTP_X_TRUST_KEY' => 'trust_unknown',
            'HTTP_X_SIGNATURE' => 't=' . time() . ',v1=' . str_repeat('a', 64),
        ], '{}');

        $resp = (new VerifyShareEnvelopeSignature())->handle($req, fn () => response('ok'));
        $this->assertEquals(401, $resp->getStatusCode());
    }

    public function test_missing_trust_key_returns_401(): void
    {
        $req = Request::create('/inbound/user-share', 'POST', [], [], [], [], '{}');
        $resp = (new VerifyShareEnvelopeSignature())->handle($req, fn () => response('ok'));
        $this->assertEquals(401, $resp->getStatusCode());
    }

    public function test_bad_signature_returns_400(): void
    {
        $env = $this->fakeEnv();
        $body = $env->toCanonicalJson();
        $badHeader = (new EnvelopeSigner('WRONG_SECRET'))->sign($env);

        $req = Request::create('/inbound/user-share', 'POST', [], [], [], [
            'HTTP_X_TRUST_KEY' => 'trust_studendly_xxx',
            'HTTP_X_SIGNATURE' => $badHeader,
        ], $body);

        $resp = (new VerifyShareEnvelopeSignature())->handle($req, fn () => response('ok'));
        $this->assertEquals(400, $resp->getStatusCode());
    }

    public function test_previous_secret_accepted_during_rotation(): void
    {
        config()->set('authservice.sharing.peers.studendly.current_secret', 'sec_new');
        config()->set('authservice.sharing.peers.studendly.previous_secret', 'sec_old');

        $env = $this->fakeEnv();
        $body = $env->toCanonicalJson();
        $header = (new EnvelopeSigner('sec_old'))->sign($env);

        $req = Request::create('/inbound/user-share', 'POST', [], [], [], [
            'HTTP_X_TRUST_KEY' => 'trust_studendly_xxx',
            'HTTP_X_SIGNATURE' => $header,
        ], $body);

        $resp = (new VerifyShareEnvelopeSignature())->handle($req, fn () => response('ok', 200));
        $this->assertEquals(200, $resp->getStatusCode());
    }

    private function fakeEnv(): ShareEnvelope
    {
        return ShareEnvelope::fromArray([
            'envelope_version' => '1',
            'message_id' => 'msg_' . bin2hex(random_bytes(8)),
            'correlation_id' => '00000000-0000-0000-0000-000000000010',
            'intent' => 'service_purchase',
            'intent_version' => '1.0',
            'source_service_id' => '00000000-0000-0000-0000-000000000001',
            'target_service_id' => '00000000-0000-0000-0000-000000000002',
            'user_id' => '00000000-0000-0000-0000-000000000003',
            'idempotency_key' => 'k_' . bin2hex(random_bytes(8)),
            'issued_at' => '2026-05-27T10:00:00Z',
            'payload' => ['order_id' => 'x'],
        ]);
    }
}
```

### Step 2 — Run, expect failure

```bash
vendor/bin/pest tests/Feature/Sharing/Inbox/VerifyShareEnvelopeSignatureTest.php
```

Expected: `Class "AuthService\Helper\Sharing\Inbox\Http\Middleware\VerifyShareEnvelopeSignature" not found`.

### Step 3 — Implement the middleware

```php
<?php
// src/Sharing/Inbox/Http/Middleware/VerifyShareEnvelopeSignature.php

namespace AuthService\Helper\Sharing\Inbox\Http\Middleware;

use AuthService\Helper\Sharing\Envelope\EnvelopeVerifier;
use AuthService\Helper\Sharing\Envelope\Exceptions\EnvelopeReplayWindowException;
use AuthService\Helper\Sharing\Envelope\Exceptions\EnvelopeSignatureMismatchException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerifyShareEnvelopeSignature
{
    public function handle(Request $request, Closure $next): mixed
    {
        $trustKey = $request->header('X-Trust-Key');
        $signature = $request->header('X-Signature');

        if (!$trustKey) {
            return $this->reject(401, 'missing_trust_key', 'X-Trust-Key header is required');
        }

        $peer = $this->resolvePeerByTrustKey($trustKey);
        if ($peer === null) {
            return $this->reject(401, 'unknown_trust_key', 'Trust key did not match any configured peer');
        }

        if (!$signature) {
            return $this->reject(400, 'missing_signature', 'X-Signature header is required');
        }

        $verifier = new EnvelopeVerifier(
            currentSecret: $peer['config']['current_secret'],
            previousSecret: $peer['config']['previous_secret'] ?? null,
            windowSeconds: (int) config('authservice.sharing.replay_window_seconds', 300),
        );

        try {
            $verifier->verify($request->getContent(), $signature);
        } catch (EnvelopeSignatureMismatchException $e) {
            return $this->reject(400, 'bad_signature', $e->getMessage());
        } catch (EnvelopeReplayWindowException $e) {
            return $this->reject(400, 'replay_window', $e->getMessage());
        }

        $request->attributes->set('source_service_id', $peer['config']['source_service_id']);
        $request->attributes->set('peer_slug', $peer['slug']);

        return $next($request);
    }

    /**
     * @return array{slug:string, config:array<string,mixed>}|null
     */
    private function resolvePeerByTrustKey(string $trustKey): ?array
    {
        $peers = (array) config('authservice.sharing.peers', []);
        foreach ($peers as $slug => $cfg) {
            if (($cfg['trust_key'] ?? null) === $trustKey) {
                return ['slug' => $slug, 'config' => $cfg];
            }
        }
        return null;
    }

    private function reject(int $status, string $reason, string $message): JsonResponse
    {
        return new JsonResponse([
            'error' => $reason,
            'message' => $message,
        ], $status);
    }
}
```

### Step 4 — Run + commit

```bash
vendor/bin/pest tests/Feature/Sharing/Inbox/VerifyShareEnvelopeSignatureTest.php
# Expected: 5 passed.

git add src/Sharing/Inbox/Http/Middleware/VerifyShareEnvelopeSignature.php \
        tests/Feature/Sharing/Inbox/VerifyShareEnvelopeSignatureTest.php
git commit -m "feat(sharing): phase D2a — webhook signature middleware

Resolves peer by X-Trust-Key against config('authservice.sharing.peers'),
delegates HMAC + replay-window verification to EnvelopeVerifier (B4),
and attaches resolved source_service_id + peer_slug to the request
attributes for the controller in D2b. Supports rotation via current +
previous secrets per-peer.

Phase: D2a of docs/plans/InterProductCommunication-2026-05-27/"
```

## Verification checklist

- [ ] Unknown trust key → 401 (not 400 — caller is unauthenticated)
- [ ] Bad signature / replay → 400 (caller is authenticated but request is malformed)
- [ ] Previous secret accepted during rotation (5th test asserts this)
- [ ] Resolved `source_service_id` flows to the controller via `$request->attributes`
