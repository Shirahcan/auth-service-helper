# Phase H1 — Integration test + docs + ship

**Repo:** `auth-service-helper` (test + docs) + `auth-service-nextjs` (docs + npm publish)
**Depends on:** B/C/D/E/F/G all green

## Goal

1. **One full-stack integration test** exercising prepare → submit → shareUser → promote in a single Orchestra Testbench app.
2. **`docs/sharing-prep.md`** with the consumer-facing guide.
3. **Bump versions, tag, push, npm publish** — same flow as v1.3 ship.

## Files

- **Create:** `tests/Integration/Sharing/PrepSignPromoteRoundTripTest.php`
- **Create:** `docs/sharing-prep.md` (in helper repo)
- **Modify:** helper `README.md` + helper `docs/sharing.md` (cross-link the new doc)
- **Modify:** helper `composer.json` (version 1.4.0)
- **Modify:** Next helper `docs/sharing.md` (mention PrepEmbed)
- **Modify:** Next helper `package.json` (version 1.4.0)
- **Modify:** both `00_MASTER_INDEX.md` Status Trackers

## Steps

### Step 1 — Integration test

```php
<?php
// tests/Integration/Sharing/PrepSignPromoteRoundTripTest.php
namespace Tests\Integration\Sharing;

use AuthService\Helper\AuthServiceHelperServiceProvider;
use AuthService\Helper\Sharing\Client\UserShareClient;
use AuthService\Helper\Sharing\Facades\Sharing;
use AuthService\Helper\Sharing\Prep\Events\PrepResourceCreated;
use AuthService\Helper\Sharing\Prep\Events\PrepResourcePromoted;
use AuthService\Helper\Sharing\Prep\Events\PrepResourceSigned;
use AuthService\Helper\Sharing\Prep\Http\Controllers\EmbedController;
use AuthService\Helper\Sharing\Prep\Http\Controllers\PrepareController;
use AuthService\Helper\Sharing\Prep\Http\Controllers\PromoteController;
use AuthService\Helper\Sharing\Prep\Intents\PrepIntentRegistry;
use AuthService\Helper\Sharing\Prep\PrepResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery;
use Orchestra\Testbench\TestCase;

class PrepSignPromoteRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [AuthServiceHelperServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    protected function defineEnvironment($app): void
    {
        // One peer entry serves both source + destination roles for this test.
        $app['config']->set('authservice.sharing.source_service_id', '11111111-1111-1111-1111-111111111111');
        $app['config']->set('authservice.sharing.peers.portify', [
            'webhook_url'       => 'http://localhost/api/v1/inbound/user-share',
            'prep_base_url'     => 'http://localhost/api/v1/sharing/prep',
            'signing_secret'    => 'shared-rt-secret',
            'trust_key'         => 'trust-rt-key',
            'target_service_id' => '22222222-2222-2222-2222-222222222222',
            'source_service_id' => '11111111-1111-1111-1111-111111111111',
            'current_secret'    => 'shared-rt-secret',
        ]);
        $app['config']->set('authservice.sharing.prep.embed_base_url', 'http://localhost');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_full_round_trip_prepare_sign_shareuser_promote(): void
    {
        Event::fake([PrepResourceCreated::class, PrepResourceSigned::class, PrepResourcePromoted::class]);

        // ── 1. PREPARE ──────────────────────────────────────────────────
        // Bridge the outbound HTTP through the in-process controller.
        $self = $this;
        Http::fake([
            'localhost/api/v1/sharing/prep/prepare' => function ($req) use ($self) {
                $r = $self->routeIntoController($req, '/api/v1/sharing/prep/prepare', PrepareController::class, 'prepare');
                return Http::response($r->getContent(), $r->getStatusCode());
            },
        ]);

        $prep = Sharing::prepare(
            peerSlug: 'portify',
            intentSlug: 'agreement_sign',
            idempotencyKey: 'studendly:checkout:ckt_1:agreement:visa-rep',
            sourceResource: ['type' => 'checkout', 'id' => 'ckt_1'],
            studentData:    ['external_id' => 'stu_1', 'email' => 'jane@x.com', 'name' => 'Jane Student'],
            payload:        ['agreement_slug' => 'visa-rep-agreement'],
            returnTo:       'http://studendly.test/done',
        );
        $this->assertNotEmpty($prep->prepId);
        $this->assertStringContainsString('/sharing/embed/agreement_sign/', $prep->embedUrl);
        Event::assertDispatched(PrepResourceCreated::class);

        // ── 2. STUDENT SIGNS IN IFRAME ──────────────────────────────────
        $submitReq = Request::create(
            "/sharing/embed/agreement_sign/{$prep->prepId}/submit",
            'POST',
            content: json_encode(['signature' => 'Jane Student', 'agreed_at' => '2026-05-27T10:00:00Z']),
        );
        $submitReq->headers->set('Content-Type', 'application/json');
        $submitResp = app(EmbedController::class)->submit($submitReq, 'agreement_sign', $prep->prepId);
        $this->assertEquals(200, $submitResp->getStatusCode());
        Event::assertDispatched(PrepResourceSigned::class);

        $row = PrepResource::find($prep->prepId);
        $this->assertEquals('signed', $row->status);

        // ── 3. PAYMENT COMPLETES → SHAREUSER ON AUTH-SERVICE ────────────
        // (Mock UserShareClient — auth-service is out of test scope)
        $shareMock = Mockery::mock(UserShareClient::class);
        $shareMock->shouldReceive('get')->andReturn([
            'id' => 'sh-1',
            'user_id' => 'u-1',
            'target_service_id' => '22222222-2222-2222-2222-222222222222',
            'status' => 'active',
        ]);
        $this->app->instance(UserShareClient::class, $shareMock);

        // ── 4. PROMOTE ──────────────────────────────────────────────────
        Http::fake([
            'localhost/api/v1/sharing/prep/' . $prep->prepId . '/promote' => function ($req) use ($self, $prep) {
                $r = $self->routeIntoController($req, "/api/v1/sharing/prep/{$prep->prepId}/promote", PromoteController::class, 'promote', $prep->prepId);
                return Http::response($r->getContent(), $r->getStatusCode());
            },
        ]);

        $promoted = Sharing::promote(
            peerSlug: 'portify',
            prepId: $prep->prepId,
            shareId: 'sh-1',
            triggerProof: ['payment_id' => 'pay-1', 'amount_cents' => 40000],
            idempotencyKey: 'studendly:checkout:ckt_1:agreement:visa-rep',
        );

        $this->assertNotEmpty($promoted->permanentResourceId);
        $this->assertEquals('pending_activation', $promoted->state);
        Event::assertDispatched(PrepResourcePromoted::class);

        $row->refresh();
        $this->assertEquals('promoted', $row->status);
        $this->assertEquals($promoted->permanentResourceId, $row->permanent_resource_id);

        // ── 5. IDEMPOTENT PROMOTE RE-CALL ───────────────────────────────
        $reTry = Sharing::promote(
            peerSlug: 'portify',
            prepId: $prep->prepId,
            shareId: 'sh-1',
            triggerProof: ['payment_id' => 'pay-1'],
            idempotencyKey: 'studendly:checkout:ckt_1:agreement:visa-rep',
        );
        $this->assertEquals($promoted->permanentResourceId, $reTry->permanentResourceId);
    }

    /**
     * Bridge an outbound Http::fake'd request into the in-process controller.
     */
    private function routeIntoController($request, string $uri, string $controller, string $method, ?string $prepId = null)
    {
        $headers = [];
        foreach ($request->headers() as $name => $values) {
            $headers['HTTP_' . strtoupper(str_replace('-', '_', $name))] = is_array($values) ? ($values[0] ?? '') : (string) $values;
        }
        $headers['CONTENT_TYPE'] = 'application/json';

        $req = Request::create(uri: $uri, method: 'POST', server: $headers, content: $request->body());
        $req->attributes->set('source_service_id', '11111111-1111-1111-1111-111111111111');
        $req->attributes->set('peer_slug', 'studendly');

        return $prepId !== null
            ? app($controller)->{$method}($req, $prepId)
            : app($controller)->{$method}($req);
    }
}
```

### Step 2 — Run the integration test

```bash
cd C:\Users\benpl\Documents\GitHub\auth-service-helper
vendor/bin/phpunit tests/Integration/Sharing/PrepSignPromoteRoundTripTest.php
# Expected: 1 passed (covering 4 event dispatches + 5 state transitions)
```

### Step 3 — `docs/sharing-prep.md`

Outline (write this out fully — the consumer should be able to integrate from this doc alone):

1. **What it is** — 2 paragraphs on Prep-Sign-Promote
2. **When to use it** vs. plain v1.3 envelope payloads
3. **Quickstart** — agreement-sign happy path with code
4. **Built-in: `agreement_sign`** — schema of signed_data + default render
5. **Custom intents** — implement `PrepIntentHandler`, register in service provider
6. **State machine + GC** — what gets deleted, what doesn't
7. **Security** — `prep_id` is the iframe security boundary, `share_id` is the post-payment gate
8. **Reverse flow** — Portify-first scenario worked through
9. **Failure recovery** — `Sharing::prepStatus()` for reconciliation; retry from `/prepare` on `prep_expired`
10. **Operational** — `sharing:gc-prep` cron, ttl tuning, dashboards

### Step 4 — Cross-link from helper README + existing docs

Append to `docs/sharing.md` (helper):

> **For two-phase cross-product flows** (sign-then-payment-then-promote, document upload slot pre-reservation, conditional resource provisioning), use the **Prep–Sign–Promote** pattern documented in `docs/sharing-prep.md`. It builds on this v1.3 envelope protocol and is the recommended pattern whenever payment success is the trigger for a peer-side permanent record.

Append a single bullet under "🔗 Sharing" in helper `README.md`:

> - **Prep–Sign–Promote pattern (v1.4)**: two-phase commit for cross-product resource provisioning (signed agreements, case reservations, embedded forms). See [docs/sharing-prep.md](docs/sharing-prep.md).

In Next helper `docs/sharing.md`, append a section after the existing pieces table:

> ### `<PrepEmbed/>` — render a peer's signing iframe
>
> ```tsx
> import { PrepEmbed } from '@benbraide/auth-service-nextjs/sharing';
> // prep is what your PHP backend got from Sharing::prepare(...)
> <PrepEmbed prepResult={prep} onSigned={({prepId}) => promote(prepId)} />
> ```
>
> Pair with the PHP helper's `Sharing::prepare` (orchestrator) and the peer's auto-mounted `/sharing/embed/*` routes. See `auth-service-helper/docs/sharing-prep.md` for the full pattern.

### Step 5 — Version bump + commit + tag + push

```bash
# helper
cd C:\Users\benpl\Documents\GitHub\auth-service-helper
# composer.json: bump version 1.3.0 → 1.4.0
git add composer.json docs/sharing-prep.md docs/sharing.md README.md \
        tests/Integration/Sharing/PrepSignPromoteRoundTripTest.php \
        docs/plans/PrepSignPromote-2026-05-27/00_MASTER_INDEX.md
git commit -m "chore(release): v1.4.0 — Prep–Sign–Promote pattern"
git tag -a v1.4.0 -m "v1.4.0 — Prep–Sign–Promote (Sharing/Prep namespace)"
git push origin main
git push origin v1.4.0

# Next helper
cd C:\Users\benpl\Documents\GitHub\auth-service-nextjs
# package.json: bump version 1.3.1 → 1.4.0
npm install --package-lock-only
git add package.json package-lock.json docs/sharing.md README.md
git commit -m "chore(release): v1.4.0 — <PrepEmbed/> + Prep message types"
git tag -a v1.4.0 -m "v1.4.0 — PrepEmbed + Prep–Sign–Promote postMessage types"
git push origin main
git push origin v1.4.0
npm publish --access public  # may need --otp=<code>
```

### Step 6 — Flip master index

Mark every checkbox in `docs/plans/PrepSignPromote-2026-05-27/00_MASTER_INDEX.md` to `[x]` with the commit SHA. Update Status Tracker to all `✅ Done`.

### Step 7 — Final report

Surface to user:

> PSP v1.4.0 shipped.
> - shirahcan/auth-service-helper v1.4.0 — main pushed, v1.4.0 tagged
> - @benbraide/auth-service-nextjs@1.4.0 — published to npm
> - docs/sharing-prep.md is the canonical consumer guide
> - Integration round-trip test green (prepare → sign → shareUser → promote → idempotent retry)

## Verification checklist

- [ ] Integration test exercises all 4 events (Created, Signed, Promoted) — Expired is covered separately by `GcPrepCommandTest`
- [ ] Idempotent promote re-call returns the same permanent_resource_id
- [ ] `docs/sharing-prep.md` is the consumer-facing guide; cross-linked from README + `docs/sharing.md`
- [ ] Helper v1.4.0 tag matches `composer.json` version
- [ ] Next helper v1.4.0 tag matches `package.json` version
- [ ] npm publish includes `dist/sharing/index.{js,mjs,d.ts}` with `PrepEmbed` export
