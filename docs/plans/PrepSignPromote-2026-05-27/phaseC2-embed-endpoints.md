# Phase C2 — /embed render + /submit endpoints

**Repo:** `auth-service-helper`
**Depends on:** B2, D1 (intent registry — for handler delegation)

## Goal

The public-facing iframe surface. `GET /sharing/embed/{intent_slug}/{prep_id}` renders HTML via the intent's handler. `POST /sharing/embed/{intent_slug}/{prep_id}/submit` captures the signature data + flips status `prepared → signed`. Both routes are auth'd by **the unguessable `prep_id` only** — no HMAC needed (this is the user's browser hitting the peer).

## Files

- **Create:** `src/Sharing/Prep/Http/Controllers/EmbedController.php`
- **Test:** `tests/Feature/Sharing/Prep/EmbedControllerTest.php`

## Steps

### Step 1 — Failing test

```php
<?php
// tests/Feature/Sharing/Prep/EmbedControllerTest.php
namespace Tests\Feature\Sharing\Prep;

use AuthService\Helper\Sharing\Prep\Events\PrepResourceSigned;
use AuthService\Helper\Sharing\Prep\Http\Controllers\EmbedController;
use AuthService\Helper\Sharing\Prep\Intents\PrepIntentRegistry;
use AuthService\Helper\Sharing\Prep\PrepResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;

class EmbedControllerTest extends TestCase
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
        // Register a test intent
        app(PrepIntentRegistry::class)->register(
            slug: 'test_sign',
            handler: new \Tests\Feature\Sharing\Prep\Fixtures\TestSignHandler(),
        );
    }

    public function test_render_returns_handler_html_for_valid_prep(): void
    {
        $row = PrepResource::factory()->create(['intent' => 'test_sign']);

        $resp = app(EmbedController::class)->render(Request::create("/sharing/embed/test_sign/{$row->id}"), 'test_sign', $row->id);
        $this->assertEquals(200, $resp->getStatusCode());
        $this->assertStringContainsString('SIGN_HERE', $resp->getContent());
    }

    public function test_render_404_when_prep_missing(): void
    {
        $resp = app(EmbedController::class)->render(Request::create('/...'), 'test_sign', '00000000-0000-0000-0000-000000000000');
        $this->assertEquals(404, $resp->getStatusCode());
    }

    public function test_render_404_when_intent_mismatch(): void
    {
        $row = PrepResource::factory()->create(['intent' => 'test_sign']);
        $resp = app(EmbedController::class)->render(Request::create('/...'), 'agreement_sign', $row->id);
        $this->assertEquals(404, $resp->getStatusCode());
    }

    public function test_render_404_when_expired(): void
    {
        $row = PrepResource::factory()->create([
            'intent' => 'test_sign',
            'expires_at' => now()->subMinute(),
        ]);
        $resp = app(EmbedController::class)->render(Request::create('/...'), 'test_sign', $row->id);
        $this->assertEquals(404, $resp->getStatusCode());
    }

    public function test_submit_captures_data_flips_to_signed_dispatches_event(): void
    {
        Event::fake([PrepResourceSigned::class]);
        $row = PrepResource::factory()->create(['intent' => 'test_sign']);

        $req = Request::create("/sharing/embed/test_sign/{$row->id}/submit", 'POST', content: json_encode([
            'signature' => 'Jane Doe',
            'agreed_at' => '2026-05-27T10:00:00Z',
        ]));
        $req->headers->set('Content-Type', 'application/json');

        $resp = app(EmbedController::class)->submit($req, 'test_sign', $row->id);
        $this->assertEquals(200, $resp->getStatusCode());

        $row->refresh();
        $this->assertEquals('signed', $row->status);
        $this->assertEquals(['signature' => 'Jane Doe', 'agreed_at' => '2026-05-27T10:00:00Z'], $row->signed_data);

        Event::assertDispatched(PrepResourceSigned::class);
    }

    public function test_submit_409_when_already_signed(): void
    {
        $row = PrepResource::factory()->create([
            'intent' => 'test_sign',
            'status' => 'signed',
            'signed_at' => now(),
        ]);
        $resp = app(EmbedController::class)->submit(
            Request::create("/sharing/embed/test_sign/{$row->id}/submit", 'POST', content: '{}'),
            'test_sign',
            $row->id,
        );
        $this->assertEquals(409, $resp->getStatusCode());
    }
}
```

```php
<?php
// tests/Feature/Sharing/Prep/Fixtures/TestSignHandler.php
namespace Tests\Feature\Sharing\Prep\Fixtures;

use AuthService\Helper\Sharing\Prep\Intents\PrepIntentHandler;
use AuthService\Helper\Sharing\Prep\PrepResource;
use AuthService\Helper\Sharing\Prep\PromoteResult;
use Symfony\Component\HttpFoundation\Response;

class TestSignHandler implements PrepIntentHandler
{
    public static function intentSlug(): string { return 'test_sign'; }
    public static function intentVersion(): string { return '1.0'; }

    public function render(PrepResource $temp): Response
    {
        return new Response('<html><body>SIGN_HERE</body></html>', 200, ['Content-Type' => 'text/html']);
    }

    public function submit(PrepResource $temp, array $signedData): void
    {
        // no extra validation for test
    }

    public function promote(PrepResource $temp, array $share): PromoteResult
    {
        return new PromoteResult(permanentResourceId: 'perm-' . $temp->id, state: 'active');
    }
}
```

### Step 2 — Implement

```php
<?php
// src/Sharing/Prep/Http/Controllers/EmbedController.php
namespace AuthService\Helper\Sharing\Prep\Http\Controllers;

use AuthService\Helper\Sharing\Prep\Events\PrepResourceSigned;
use AuthService\Helper\Sharing\Prep\Intents\PrepIntentRegistry;
use AuthService\Helper\Sharing\Prep\PrepResource;
use AuthService\Helper\Sharing\Prep\PrepResourceRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpFoundation\Response;

class EmbedController
{
    public function __construct(
        protected PrepResourceRepository $repo,
        protected PrepIntentRegistry $intents,
    ) {}

    public function render(Request $request, string $intentSlug, string $prepId): Response
    {
        $row = $this->repo->find($prepId);
        if (!$row || $row->intent !== $intentSlug || $row->hasExpired()) {
            return new Response('Not found', 404);
        }
        if (!$this->intents->has($intentSlug)) {
            return new Response('Intent handler missing', 500);
        }
        if (in_array($row->status, [PrepResource::STATUS_PROMOTED, PrepResource::STATUS_EXPIRED], true)) {
            return new Response('Not available', 410);
        }

        return $this->intents->get($intentSlug)->render($row);
    }

    public function submit(Request $request, string $intentSlug, string $prepId): JsonResponse
    {
        $row = $this->repo->find($prepId);
        if (!$row || $row->intent !== $intentSlug || $row->hasExpired()) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }
        if (!$this->intents->has($intentSlug)) {
            return new JsonResponse(['error' => 'no_handler'], 500);
        }
        if ($row->status !== PrepResource::STATUS_PREPARED) {
            return new JsonResponse([
                'error'  => 'invalid_state',
                'status' => $row->status,
            ], 409);
        }

        try {
            $signedData = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($signedData)) {
                throw new \JsonException('expected JSON object');
            }
        } catch (\JsonException $e) {
            return new JsonResponse(['error' => 'invalid_body', 'message' => $e->getMessage()], 400);
        }

        $handler = $this->intents->get($intentSlug);
        try {
            $handler->submit($row, $signedData);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'handler_rejected', 'message' => $e->getMessage()], 422);
        }

        $this->repo->markSigned(
            $row,
            signedData: $signedData,
            ttlSignedHours: (int) config('authservice.sharing.prep.ttl_signed_hours', 72),
        );

        Event::dispatch(new PrepResourceSigned($row->fresh()));

        return new JsonResponse([
            'status'    => 'signed',
            'signed_at' => $row->fresh()->signed_at?->toIso8601String(),
        ], 200);
    }
}
```

### Step 3 — Run + commit

```bash
vendor/bin/phpunit tests/Feature/Sharing/Prep/EmbedControllerTest.php
# Expected: 6 passed.

git add src/Sharing/Prep/Http/Controllers/EmbedController.php tests/Feature/Sharing/Prep/
git commit -m "feat(sharing): phase C2 — /embed render + /submit endpoints

Two public routes (auth'd by the unguessable prep_id, no HMAC):
- GET /sharing/embed/{slug}/{prep_id} renders the iframe surface via
  the intent handler.
- POST /sharing/embed/{slug}/{prep_id}/submit captures signed_data,
  flips status prepared→signed, extends TTL, dispatches PrepResourceSigned.

404 on missing/expired/intent-mismatch; 409 if not in 'prepared' state.

Phase: C2 of docs/plans/PrepSignPromote-2026-05-27/"
```

## Verification checklist

- [ ] Embed render returns handler HTML; 404 on missing/expired/mismatched-intent
- [ ] Submit 409s if status is not `prepared` (covers replay attempts)
- [ ] Submit dispatches `PrepResourceSigned` AFTER markSigned + with fresh row
- [ ] Handler-side validation failure → 422 (so iframes can show inline errors)
