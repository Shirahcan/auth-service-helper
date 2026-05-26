# Phase A4 — `HandoffTokenController::exchange` endpoint

**Repo:** `auth-service`
**Spec section:** §4
**Depends on:** A1, A2, A3

## Goal

`POST /api/v1/auth/handoff-tokens/{token}/exchange` — destination service redeems a token atomically (single-use) and gets back a session token + the resolved user record.

## Files

- **Modify:** `project/app/Http/Controllers/Api/V1/HandoffTokenController.php` (add `exchange` method)
- **Test:** `project/tests/Feature/Api/V1/HandoffTokenExchangeTest.php`

## Steps

### Step 1 — Write the failing exchange test

```php
<?php
// project/tests/Feature/Api/V1/HandoffTokenExchangeTest.php

namespace Tests\Feature\Api\V1;

use App\Models\HandoffToken;
use App\Models\Service;
use App\Models\ServiceKey;
use App\Models\User;
use App\Models\UserShare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HandoffTokenExchangeTest extends TestCase
{
    use RefreshDatabase;

    private function mintFor(Service $target, ?User $user = null): array
    {
        $user = $user ?? User::factory()->create();
        $share = UserShare::factory()->create([
            'user_id' => $user->id,
            'target_service_id' => $target->id,
            'status' => 'active',
        ]);
        $raw = Str::random(40);
        $token = HandoffToken::factory()->create([
            'token_hash' => HandoffToken::hashToken($raw),
            'share_id' => $share->id,
            'target_service_id' => $target->id,
            'next_path' => '/cases/abc',
        ]);
        return [$raw, $token, $user];
    }

    public function test_exchange_returns_session_and_user(): void
    {
        $target = Service::factory()->create();
        [$raw, $token, $user] = $this->mintFor($target);
        $key = ServiceKey::factory()->create(['service_id' => $target->id]);

        $response = $this->postJson(
            "/api/v1/auth/handoff-tokens/{$raw}/exchange",
            [],
            ['X-API-KEY' => $key->plaintext]
        );

        $response->assertOk()
            ->assertJsonStructure(['user' => ['uuid', 'name', 'email'], 'session_token', 'share_id', 'next_path']);

        $this->assertEquals($user->uuid, $response->json('user.uuid'));
        $this->assertNotNull($token->fresh()->consumed_at);
    }

    public function test_exchange_is_single_use(): void
    {
        $target = Service::factory()->create();
        [$raw] = $this->mintFor($target);
        $key = ServiceKey::factory()->create(['service_id' => $target->id]);

        $this->postJson("/api/v1/auth/handoff-tokens/{$raw}/exchange", [], ['X-API-KEY' => $key->plaintext])->assertOk();

        $second = $this->postJson("/api/v1/auth/handoff-tokens/{$raw}/exchange", [], ['X-API-KEY' => $key->plaintext]);
        $second->assertStatus(410)->assertJsonPath('error', 'token_consumed');
    }

    public function test_exchange_rejects_wrong_target(): void
    {
        $rightTarget = Service::factory()->create();
        [$raw] = $this->mintFor($rightTarget);
        $wrongTarget = Service::factory()->create();
        $key = ServiceKey::factory()->create(['service_id' => $wrongTarget->id]);

        $this->postJson("/api/v1/auth/handoff-tokens/{$raw}/exchange", [], ['X-API-KEY' => $key->plaintext])
            ->assertStatus(403)
            ->assertJsonPath('error', 'wrong_target');
    }

    public function test_exchange_rejects_expired(): void
    {
        $target = Service::factory()->create();
        $share = UserShare::factory()->create(['target_service_id' => $target->id]);
        $raw = Str::random(40);
        HandoffToken::factory()->expired()->create([
            'token_hash' => HandoffToken::hashToken($raw),
            'share_id' => $share->id,
            'target_service_id' => $target->id,
        ]);
        $key = ServiceKey::factory()->create(['service_id' => $target->id]);

        $this->postJson("/api/v1/auth/handoff-tokens/{$raw}/exchange", [], ['X-API-KEY' => $key->plaintext])
            ->assertStatus(410)
            ->assertJsonPath('error', 'token_expired');
    }
}
```

### Step 2 — Run, expect failure (404 on route)

```bash
cd C:\Users\benpl\Documents\GitHub\auth-service\project
php artisan test --filter=HandoffTokenExchangeTest
```

### Step 3 — Implement exchange (atomic single-use)

Add to `HandoffTokenController`:

```php
public function exchange(Request $request, string $rawToken): JsonResponse
{
    $callerService = $request->attributes->get('authenticated_service');
    abort_unless($callerService instanceof \App\Models\Service, 401);

    $hash = HandoffToken::hashToken($rawToken);

    // Atomic single-use enforcement
    $affected = \DB::table('handoff_tokens')
        ->where('token_hash', $hash)
        ->whereNull('consumed_at')
        ->where('expires_at', '>', now())
        ->where('target_service_id', $callerService->id)
        ->update([
            'consumed_at' => now(),
            'consumed_by_service_id' => $callerService->id,
        ]);

    if ($affected !== 1) {
        $existing = HandoffToken::where('token_hash', $hash)->first();
        if (!$existing) {
            return response()->json(['error' => 'token_unknown'], 404);
        }
        \DB::table('handoff_tokens')->where('id', $existing->id)->increment('replay_attempts');
        if ($existing->consumed_at) {
            return response()->json(['error' => 'token_consumed'], 410);
        }
        if ($existing->isExpired()) {
            return response()->json(['error' => 'token_expired'], 410);
        }
        if ($existing->target_service_id !== $callerService->id) {
            return response()->json(['error' => 'wrong_target'], 403);
        }
        return response()->json(['error' => 'token_unusable'], 410);
    }

    $token = HandoffToken::where('token_hash', $hash)->firstOrFail();
    $share = \App\Models\UserShare::findOrFail($token->share_id);
    $user = \App\Models\User::findOrFail($share->user_id);

    // Issue a short-lived session token scoped to (user, target_service).
    // Reuses existing UserSession infrastructure.
    $session = \App\Models\UserSession::issueForShare(
        userId: $user->id,
        serviceId: $callerService->id,
        ttlSeconds: 3600
    );

    return response()->json([
        'user' => [
            'uuid' => $user->uuid,
            'name' => $user->name,
            'email' => $user->email,
        ],
        'session_token' => $session->plaintext_token,
        'share_id' => $share->id,
        'next_path' => $token->next_path,
    ]);
}
```

### Step 4 — Wire route + run

In `project/routes/api/v1.php`, add to the same group as A3:

```php
Route::post('handoff-tokens/{token}/exchange',
    [\App\Http\Controllers\Api\V1\HandoffTokenController::class, 'exchange']
);
```

```bash
php artisan test --filter=HandoffTokenExchangeTest
# Expected: 4 passed.
```

### Step 5 — Commit

```bash
git add project/app/Http/Controllers/Api/V1/HandoffTokenController.php \
        project/routes/api/v1.php \
        project/tests/Feature/Api/V1/HandoffTokenExchangeTest.php
git commit -m "feat(sharing): phase A4 — handoff-tokens exchange endpoint

Atomic single-use redemption via UPDATE ... WHERE consumed_at IS NULL,
returns short-lived session token + user + next_path. Replay attempts
incremented on every rejected redemption.

Phase: A4 of docs/plans/InterProductCommunication-2026-05-27/"
```

## Note on `UserSession::issueForShare`

This is a small helper to be added on the existing `UserSession` model. If `issueForShare` does not exist, it should be a 5-line method that constructs a session row with the correct fields. Add it as part of this phase if needed. Do not over-engineer — reuse existing session-creation logic.
