# Phase A3 — `HandoffTokenController::mint` endpoint

**Repo:** `auth-service`
**Spec section:** §4
**Depends on:** A1, A2

## Goal

Implement `POST /api/v1/auth/handoff-tokens` — source service mints a single-use token bound to a share + target service.

## Files

- **Create:** `project/app/Http/Controllers/Api/V1/HandoffTokenController.php`
- **Test:** `project/tests/Feature/Api/V1/HandoffTokenMintTest.php`

## Steps

### Step 1 — Write the failing endpoint test

```php
<?php
// project/tests/Feature/Api/V1/HandoffTokenMintTest.php

namespace Tests\Feature\Api\V1;

use App\Models\HandoffToken;
use App\Models\Service;
use App\Models\ServiceKey;
use App\Models\UserShare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandoffTokenMintTest extends TestCase
{
    use RefreshDatabase;

    public function test_mint_returns_token_and_redirect_url(): void
    {
        $source = Service::factory()->create();
        $target = Service::factory()->create(['base_url' => 'https://portify.app']);
        $share = UserShare::factory()->create([
            'source_service_id' => $source->id,
            'target_service_id' => $target->id,
        ]);
        $key = ServiceKey::factory()->create(['service_id' => $source->id]);

        $response = $this->postJson('/api/v1/auth/handoff-tokens', [
            'share_id' => $share->id,
            'next_path' => '/cases/abc',
        ], ['X-API-KEY' => $key->plaintext]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'redirect_url', 'expires_at'])
            ->assertJsonPath('redirect_url', fn ($url) => str_starts_with($url, 'https://portify.app/auth/handoff?token='));

        $this->assertCount(1, HandoffToken::all());
    }

    public function test_mint_rejects_share_not_owned_by_caller(): void
    {
        $someoneElse = Service::factory()->create();
        $caller = Service::factory()->create();
        $share = UserShare::factory()->create(['source_service_id' => $someoneElse->id]);
        $key = ServiceKey::factory()->create(['service_id' => $caller->id]);

        $response = $this->postJson('/api/v1/auth/handoff-tokens', [
            'share_id' => $share->id,
        ], ['X-API-KEY' => $key->plaintext]);

        $response->assertForbidden();
    }

    public function test_mint_rejects_revoked_share(): void
    {
        $source = Service::factory()->create();
        $share = UserShare::factory()->create([
            'source_service_id' => $source->id,
            'status' => 'revoked',
        ]);
        $key = ServiceKey::factory()->create(['service_id' => $source->id]);

        $response = $this->postJson('/api/v1/auth/handoff-tokens', [
            'share_id' => $share->id,
        ], ['X-API-KEY' => $key->plaintext]);

        $response->assertStatus(409)->assertJsonPath('error', 'share_revoked');
    }
}
```

### Step 2 — Run, expect failure

```bash
cd C:\Users\benpl\Documents\GitHub\auth-service\project
php artisan test --filter=HandoffTokenMintTest
```

Expected: 404 on the route (controller doesn't exist).

### Step 3 — Implement the controller

```php
<?php
// project/app/Http/Controllers/Api/V1/HandoffTokenController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\HandoffToken;
use App\Models\Service;
use App\Models\UserShare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HandoffTokenController extends Controller
{
    public function mint(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'share_id' => 'required|uuid|exists:user_shares,id',
            'next_path' => 'nullable|string|max:2048',
        ]);

        $callerService = $request->attributes->get('authenticated_service');
        abort_unless($callerService instanceof Service, 401);

        $share = UserShare::findOrFail($validated['share_id']);

        if ($share->source_service_id !== $callerService->id) {
            return response()->json(['error' => 'share_not_owned'], 403);
        }
        if ($share->status === 'revoked') {
            return response()->json(['error' => 'share_revoked'], 409);
        }

        $target = Service::findOrFail($share->target_service_id);
        $rawToken = Str::random(40);
        $now = now();

        $token = HandoffToken::create([
            'token_hash' => HandoffToken::hashToken($rawToken),
            'share_id' => $share->id,
            'target_service_id' => $target->id,
            'next_path' => $validated['next_path'] ?? null,
            'minted_by_service_id' => $callerService->id,
            'expires_at' => $now->copy()->addSeconds(HandoffToken::DEFAULT_TTL_SECONDS),
            'created_at' => $now,
        ]);

        $redirectUrl = rtrim($target->base_url, '/').'/auth/handoff?token='.urlencode($rawToken);
        if ($validated['next_path'] ?? null) {
            $redirectUrl .= '&next='.urlencode($validated['next_path']);
        }

        return response()->json([
            'token' => $rawToken,
            'redirect_url' => $redirectUrl,
            'expires_at' => $token->expires_at->toIso8601String(),
        ]);
    }
}
```

### Step 4 — Wire the route (temporarily here; A5 polishes)

In `project/routes/api/v1.php`, add inside the existing `Route::prefix('auth')->middleware('service-key')->group(...)` block:

```php
Route::post('handoff-tokens', [\App\Http\Controllers\Api\V1\HandoffTokenController::class, 'mint']);
```

### Step 5 — Run + commit

```bash
php artisan test --filter=HandoffTokenMintTest
# Expected: 3 passed.

git add project/app/Http/Controllers/Api/V1/HandoffTokenController.php \
        project/routes/api/v1.php \
        project/tests/Feature/Api/V1/HandoffTokenMintTest.php
git commit -m "feat(sharing): phase A3 — handoff-tokens mint endpoint

POST /auth/handoff-tokens; only source service of share can mint;
revoked shares rejected; token in plaintext returned once, hash stored.
Redirect URL composed against target service's base_url.

Phase: A3 of docs/plans/InterProductCommunication-2026-05-27/"
```
