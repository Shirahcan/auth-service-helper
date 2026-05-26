# Phase A6 — Auth-service iframe `ADD_ACCOUNT_WITH_TOKEN` handler

**Repo:** `auth-service`
**Spec section:** §4 (Auth-Service Iframe Addition) + §8 (Next helper postMessage)
**Depends on:** A4

## Goal

Add a single postMessage handler inside the auth-service account-switcher iframe so that the Next helper's `multiAccountAutoAdd` primitive (Phase F4) can push a new account into the destination domain's session cookies.

## Files

- **Modify:** `project/resources/js/account-switcher/iframe-message-handler.{ts,js}` (whichever exists)
- **Test:** `project/tests/Feature/AccountSwitcher/AddAccountWithTokenTest.php` (server-side: validates the `/internal/account-switcher/add-account-with-token` AJAX endpoint that the iframe handler calls)

## Steps

### Step 1 — Write the failing server-side test

The iframe's postMessage handler eventually calls a backend endpoint (`/internal/account-switcher/add-account-with-token`) that:
1. Validates the `session_token` (from A4's exchange)
2. Resolves the user
3. Issues the destination-domain session cookie under the auth-service's cookie scope

```php
<?php
// project/tests/Feature/AccountSwitcher/AddAccountWithTokenTest.php

namespace Tests\Feature\AccountSwitcher;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddAccountWithTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_session_token_writes_cookie_and_returns_account(): void
    {
        $user = User::factory()->create();
        $session = UserSession::factory()->create([
            'user_id' => $user->id,
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->postJson('/internal/account-switcher/add-account-with-token', [
            'account_uuid' => $user->uuid,
            'session_token' => $session->plaintext_token,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['account' => ['uuid', 'name', 'email']])
            ->assertCookie('auth_session_'.$user->uuid);
    }

    public function test_invalid_session_token_returns_401(): void
    {
        $user = User::factory()->create();
        $response = $this->postJson('/internal/account-switcher/add-account-with-token', [
            'account_uuid' => $user->uuid,
            'session_token' => 'invalid',
        ]);
        $response->assertUnauthorized();
    }
}
```

### Step 2 — Run, expect failure

```bash
cd C:\Users\benpl\Documents\GitHub\auth-service\project
php artisan test --filter=AddAccountWithTokenTest
```

### Step 3 — Implement the controller + route

Create `project/app/Http/Controllers/Internal/AccountSwitcherController.php`:

```php
<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountSwitcherController extends Controller
{
    public function addAccountWithToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_uuid' => 'required|uuid',
            'session_token' => 'required|string',
        ]);

        $session = UserSession::findByPlaintext($validated['session_token']);
        if (!$session || $session->isExpired() || $session->user->uuid !== $validated['account_uuid']) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        $user = $session->user;
        $cookieName = 'auth_session_'.$user->uuid;
        $cookie = cookie(
            $cookieName,
            $validated['session_token'],
            minutes: 60,
            path: '/',
            domain: config('session.domain'),
            secure: true,
            httpOnly: true,
            raw: false,
            sameSite: 'lax'
        );

        return response()
            ->json(['account' => ['uuid' => $user->uuid, 'name' => $user->name, 'email' => $user->email]])
            ->withCookie($cookie);
    }
}
```

Add to `project/routes/web.php` (must be `web` middleware group for cookies):

```php
Route::post('/internal/account-switcher/add-account-with-token',
    [\App\Http\Controllers\Internal\AccountSwitcherController::class, 'addAccountWithToken']
)->middleware('web');
```

### Step 4 — Wire the iframe postMessage handler

In the iframe JS (locate via `Grep` for existing `MESSAGE_TYPES` constants — likely `project/resources/js/account-switcher/`), add:

```ts
// ~30 lines, inside the existing message dispatcher switch
case 'add-account-with-token': {
    const { accountUuid, sessionToken } = event.data.payload;
    const res = await fetch('/internal/account-switcher/add-account-with-token', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
        body: JSON.stringify({ account_uuid: accountUuid, session_token: sessionToken }),
    });
    if (!res.ok) {
        sendToParent('add-account-with-token-failed', { reason: 'http_'+res.status });
        return;
    }
    const { account } = await res.json();
    appendAccount(account);    // existing internal fn that updates iframe state + emits SESSION_CHANGED
    sendToParent('add-account-with-token-ok', { accountUuid });
    break;
}
```

### Step 5 — Run + commit

```bash
php artisan test --filter=AddAccountWithTokenTest
# Expected: 2 passed.

# (build the iframe JS if there's a Vite/Mix step)
npm run build

git add project/app/Http/Controllers/Internal/AccountSwitcherController.php \
        project/routes/web.php \
        project/resources/js/account-switcher/ \
        project/tests/Feature/AccountSwitcher/AddAccountWithTokenTest.php
git commit -m "feat(sharing): phase A6 — iframe ADD_ACCOUNT_WITH_TOKEN handler

Internal endpoint + iframe handler that writes a destination-scoped
session cookie from a short-lived session token (issued by handoff
exchange). Enables Next helper's multiAccountAutoAdd (F4) without
introducing a parallel auth path.

Phase: A6 of docs/plans/InterProductCommunication-2026-05-27/"
```
