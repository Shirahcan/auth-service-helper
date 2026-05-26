# Phase C1a — `UserShareClient::shareUser`

**Repo:** `auth-service-helper`
**Spec section:** §7 "Source-Side API" + §11 "Collision Handling"
**Depends on:** B1 (ServiceProvider scaffolding)

## Goal

Wrap `POST /api/v1/auth/user-shares` from auth-service in a typed PHP client. Idempotent (auth-service enforces `UNIQUE(user_id, target_service_id)`). On `409` with a conflict-shaped response, return a `ShareResult` whose `$conflict` slot is populated — the facade (C4) decides whether to bubble it as an exception.

## Files

- **Create:** `src/Sharing/Client/UserShareClient.php`
- **Create:** `src/Sharing/Client/ShareResult.php`
- **Create:** `src/Sharing/Client/ConflictRef.php`
- **Test:** `tests/Unit/Sharing/Client/UserShareClientShareUserTest.php`

## Steps

### Step 1 — Failing test

```php
<?php
// tests/Unit/Sharing/Client/UserShareClientShareUserTest.php

namespace Tests\Unit\Sharing\Client;

use AuthService\Helper\Sharing\Client\ConflictRef;
use AuthService\Helper\Sharing\Client\ShareResult;
use AuthService\Helper\Sharing\Client\UserShareClient;
use AuthService\Helper\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class UserShareClientShareUserTest extends TestCase
{
    public function test_share_user_returns_share_result_on_201(): void
    {
        Http::fake([
            '*/api/v1/auth/user-shares' => Http::response([
                'data' => [
                    'id' => 'share_01HX',
                    'status' => 'active',
                    'user_id' => 'user_01',
                    'target_service_id' => 'svc_target',
                    'intent' => 'service_purchase',
                    'granted_roles' => ['client'],
                ],
            ], 201),
        ]);

        $client = $this->app->make(UserShareClient::class);
        $result = $client->shareUser(
            userId: 'user_01',
            targetServiceId: 'svc_target',
            intent: 'service_purchase',
            grantedRoles: ['client'],
            metadata: ['correlation_id' => 'corr_1'],
        );

        $this->assertInstanceOf(ShareResult::class, $result);
        $this->assertEquals('share_01HX', $result->shareId);
        $this->assertEquals('active', $result->status);
        $this->assertNull($result->conflict);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/api/v1/auth/user-shares')
                && $request['user_id'] === 'user_01'
                && $request['target_service_id'] === 'svc_target'
                && $request['intent'] === 'service_purchase'
                && $request->hasHeader('X-API-Key');
        });
    }

    public function test_share_user_idempotent_returns_existing_on_200(): void
    {
        Http::fake([
            '*/api/v1/auth/user-shares' => Http::response([
                'data' => ['id' => 'share_existing', 'status' => 'active'],
            ], 200),
        ]);

        $client = $this->app->make(UserShareClient::class);
        $result = $client->shareUser('user_01', 'svc_target', 'service_purchase');

        $this->assertEquals('share_existing', $result->shareId);
        $this->assertNull($result->conflict);
    }

    public function test_share_user_returns_conflict_result_on_409(): void
    {
        Http::fake([
            '*/api/v1/auth/user-shares' => Http::response([
                'error' => 'conflict',
                'conflict' => [
                    'id' => 'conflict_01',
                    'source_user_id' => 'user_01',
                    'target_existing_user_id' => 'user_99',
                    'resolution_url' => '/api/v1/auth/user-share-conflicts/conflict_01',
                ],
            ], 409),
        ]);

        $client = $this->app->make(UserShareClient::class);
        $result = $client->shareUser('user_01', 'svc_target', 'service_purchase');

        $this->assertNull($result->shareId);
        $this->assertEquals('conflict', $result->status);
        $this->assertInstanceOf(ConflictRef::class, $result->conflict);
        $this->assertEquals('conflict_01', $result->conflict->conflictId);
        $this->assertEquals('user_99', $result->conflict->targetExistingUserId);
    }
}
```

### Step 2 — Run, expect failure

```bash
cd C:\Users\benpl\Documents\GitHub\auth-service-helper
vendor/bin/pest tests/Unit/Sharing/Client/UserShareClientShareUserTest.php
```

Expected: class-not-found on `UserShareClient`/`ShareResult`/`ConflictRef`.

### Step 3 — Implement the value objects + client

```php
<?php
// src/Sharing/Client/ConflictRef.php

namespace AuthService\Helper\Sharing\Client;

final class ConflictRef
{
    public function __construct(
        public readonly string $conflictId,
        public readonly ?string $sourceUserId,
        public readonly ?string $targetExistingUserId,
        public readonly ?string $resolutionUrl,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            conflictId: $raw['id'] ?? $raw['conflict_id'],
            sourceUserId: $raw['source_user_id'] ?? null,
            targetExistingUserId: $raw['target_existing_user_id'] ?? null,
            resolutionUrl: $raw['resolution_url'] ?? null,
        );
    }
}
```

```php
<?php
// src/Sharing/Client/ShareResult.php

namespace AuthService\Helper\Sharing\Client;

final class ShareResult
{
    public function __construct(
        public readonly ?string $shareId,
        public readonly string $status,
        public readonly ?string $userId = null,
        public readonly ?string $targetServiceId = null,
        public readonly ?string $intent = null,
        public readonly array $grantedRoles = [],
        public readonly ?ConflictRef $conflict = null,
        public readonly array $raw = [],
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            shareId: $raw['id'] ?? null,
            status: $raw['status'] ?? 'unknown',
            userId: $raw['user_id'] ?? null,
            targetServiceId: $raw['target_service_id'] ?? null,
            intent: $raw['intent'] ?? null,
            grantedRoles: $raw['granted_roles'] ?? [],
            conflict: null,
            raw: $raw,
        );
    }

    public static function fromConflict(array $conflict): self
    {
        return new self(
            shareId: null,
            status: 'conflict',
            conflict: ConflictRef::fromArray($conflict),
            raw: ['conflict' => $conflict],
        );
    }
}
```

```php
<?php
// src/Sharing/Client/UserShareClient.php

namespace AuthService\Helper\Sharing\Client;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class UserShareClient
{
    protected string $baseUrl;
    protected string $apiKey;
    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('authservice.auth_service_base_url'), '/');
        $this->apiKey = (string) config('authservice.auth_service_api_key');
        $this->timeout = (int) config('authservice.timeout', 5);
    }

    public function shareUser(
        string $userId,
        string $targetServiceId,
        string $intent,
        array $grantedRoles = [],
        array $metadata = [],
    ): ShareResult {
        $response = $this->http()->post($this->url('user-shares'), [
            'user_id' => $userId,
            'target_service_id' => $targetServiceId,
            'intent' => $intent,
            'granted_roles' => $grantedRoles,
            'metadata' => $metadata,
        ]);

        if ($response->status() === 409 && is_array($response->json('conflict'))) {
            return ShareResult::fromConflict($response->json('conflict'));
        }

        $response->throw();
        $data = $response->json('data') ?? $response->json() ?? [];
        return ShareResult::fromArray($data);
    }

    protected function http()
    {
        return Http::withHeaders([
            'Accept' => 'application/json',
            'X-API-Key' => $this->apiKey,
        ])->timeout($this->timeout)->acceptJson();
    }

    protected function url(string $path): string
    {
        return $this->baseUrl.'/api/v1/auth/'.ltrim($path, '/');
    }
}
```

### Step 4 — Bind in SharingServiceProvider

In `src/Sharing/SharingServiceProvider.php` `register()`:

```php
$this->app->singleton(\AuthService\Helper\Sharing\Client\UserShareClient::class);
```

### Step 5 — Run + commit

```bash
vendor/bin/pest tests/Unit/Sharing/Client/UserShareClientShareUserTest.php
# Expected: 3 passed.

git add src/Sharing/Client/ src/Sharing/SharingServiceProvider.php tests/Unit/Sharing/Client/
git commit -m "feat(sharing): phase C1a — UserShareClient::shareUser

Wraps POST /api/v1/auth/user-shares. Returns ShareResult; 409 with
conflict payload is returned (not thrown) so the Sharing facade can
decide whether to bubble it. Reuses config('authservice') for base URL
+ X-API-Key.

Phase: C1a of docs/plans/InterProductCommunication-2026-05-27/"
```
