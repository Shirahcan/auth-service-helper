# Phase C1c — `UserShareClient::revoke` + `bulkRevoke`

**Repo:** `auth-service-helper`
**Spec section:** §7 + §12 "Revocation Flow"
**Depends on:** C1a, C1b

## Goal

Add `revoke($shareId, ?$reason)` and `bulkRevoke($input)` to `UserShareClient`. Both are idempotent — auth-service deduplicates re-revokes server-side. `bulkRevoke` accepts EITHER a list of share IDs OR a `['user_ids' => [...], 'target_service_id' => '...']` selector and passes it through.

## Files

- **Modify:** `src/Sharing/Client/UserShareClient.php`
- **Test:** `tests/Unit/Sharing/Client/UserShareClientRevokeTest.php`

## Steps

### Step 1 — Failing test

```php
<?php
// tests/Unit/Sharing/Client/UserShareClientRevokeTest.php

namespace Tests\Unit\Sharing\Client;

use AuthService\Helper\Sharing\Client\UserShareClient;
use AuthService\Helper\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class UserShareClientRevokeTest extends TestCase
{
    public function test_revoke_sends_delete_with_reason(): void
    {
        Http::fake([
            '*/api/v1/auth/user-shares/share_xyz' => Http::response([
                'data' => ['id' => 'share_xyz', 'status' => 'revoked'],
            ], 200),
        ]);

        $client = $this->app->make(UserShareClient::class);
        $result = $client->revoke('share_xyz', reason: 'admission_withdrawn');

        $this->assertEquals('revoked', $result->status);
        $this->assertEquals('share_xyz', $result->shareId);

        Http::assertSent(function ($r) {
            return $r->method() === 'DELETE'
                && str_ends_with($r->url(), '/api/v1/auth/user-shares/share_xyz')
                && $r['reason'] === 'admission_withdrawn';
        });
    }

    public function test_revoke_without_reason_is_accepted(): void
    {
        Http::fake([
            '*/api/v1/auth/user-shares/share_xyz' => Http::response([
                'data' => ['id' => 'share_xyz', 'status' => 'revoked'],
            ], 200),
        ]);

        $client = $this->app->make(UserShareClient::class);
        $result = $client->revoke('share_xyz');

        $this->assertEquals('revoked', $result->status);
    }

    public function test_bulk_revoke_accepts_share_id_list(): void
    {
        Http::fake([
            '*/api/v1/auth/user-shares/bulk-revoke' => Http::response([
                'data' => ['revoked_count' => 3, 'share_ids' => ['a', 'b', 'c']],
            ], 200),
        ]);

        $client = $this->app->make(UserShareClient::class);
        $payload = $client->bulkRevoke(['a', 'b', 'c'], reason: 'cleanup');

        $this->assertEquals(3, $payload['revoked_count']);

        Http::assertSent(function ($r) {
            return $r->method() === 'POST'
                && str_ends_with($r->url(), '/api/v1/auth/user-shares/bulk-revoke')
                && $r['share_ids'] === ['a', 'b', 'c']
                && $r['reason'] === 'cleanup';
        });
    }

    public function test_bulk_revoke_accepts_selector_array(): void
    {
        Http::fake([
            '*/api/v1/auth/user-shares/bulk-revoke' => Http::response([
                'data' => ['revoked_count' => 7],
            ], 200),
        ]);

        $client = $this->app->make(UserShareClient::class);
        $client->bulkRevoke([
            'user_ids' => ['u1', 'u2'],
            'target_service_id' => 'svc_target',
        ]);

        Http::assertSent(function ($r) {
            return $r['user_ids'] === ['u1', 'u2']
                && $r['target_service_id'] === 'svc_target';
        });
    }
}
```

### Step 2 — Run, expect failure

```bash
vendor/bin/pest tests/Unit/Sharing/Client/UserShareClientRevokeTest.php
```

Expected: `BadMethodCallException` for `revoke`/`bulkRevoke`.

### Step 3 — Implement on `UserShareClient`

Append:

```php
public function revoke(string $shareId, ?string $reason = null): ShareResult
{
    $body = $reason !== null ? ['reason' => $reason] : [];
    $response = $this->http()->delete($this->url('user-shares/'.$shareId), $body);
    $response->throw();
    return ShareResult::fromArray($response->json('data') ?? $response->json() ?? []);
}

/**
 * @param array $input Either a flat list of share IDs OR a selector
 *                     ['user_ids' => [...], 'target_service_id' => '...']
 */
public function bulkRevoke(array $input, ?string $reason = null): array
{
    $body = $this->isAssociative($input) ? $input : ['share_ids' => array_values($input)];
    if ($reason !== null) {
        $body['reason'] = $reason;
    }

    $response = $this->http()->post($this->url('user-shares/bulk-revoke'), $body);
    $response->throw();
    return $response->json('data') ?? $response->json() ?? [];
}

protected function isAssociative(array $arr): bool
{
    if ($arr === []) return false;
    return array_keys($arr) !== range(0, count($arr) - 1);
}
```

Note: Laravel's `Http::delete($url, $body)` sends the body as form-encoded by default for the `delete` verb on some versions; if auth-service requires JSON, swap to:

```php
$response = $this->http()->send('DELETE', $this->url('user-shares/'.$shareId), [
    'json' => $body,
]);
```

### Step 4 — No additional wiring

Already bound from C1a.

### Step 5 — Run + commit

```bash
vendor/bin/pest tests/Unit/Sharing/Client/UserShareClientRevokeTest.php
# Expected: 4 passed.

git add src/Sharing/Client/UserShareClient.php tests/Unit/Sharing/Client/UserShareClientRevokeTest.php
git commit -m "feat(sharing): phase C1c — UserShareClient revoke + bulkRevoke

revoke() wraps DELETE /user-shares/{id}; bulkRevoke() wraps
POST /user-shares/bulk-revoke and accepts both a flat share-id list
and an associative selector. Idempotent end-to-end — auth-service
handles dedupe.

Phase: C1c of docs/plans/InterProductCommunication-2026-05-27/"
```
