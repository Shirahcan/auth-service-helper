# Phase C4 — `Sharing` facade wiring

**Repo:** `auth-service-helper`
**Spec section:** §7 "Source-Side API"
**Depends on:** C1a, C1b, C1c, C2a, C2b, C3

## Goal

Compose the lower-level clients (`UserShareClient`, `HandoffTokenClient`) — plus the outbox repository that arrives in E2 — into a single ergonomic static facade `AuthService\Helper\Sharing\Facades\Sharing`. This is the surface products call (`Sharing::shareUser(...)`, etc.). Methods that depend on later phases are stubbed with a `TODO(phase E2)` body that throws `\LogicException` so callers fail loud until the dependent phase ships.

## Files

- **Create:** `src/Sharing/SharingService.php` (the underlying service class)
- **Create:** `src/Sharing/Facades/Sharing.php` (the `Facade` subclass)
- **Modify:** `src/Sharing/SharingServiceProvider.php` (bind `SharingService` + register alias)
- **Test:** `tests/Unit/Sharing/SharingFacadeTest.php`

## Steps

### Step 1 — Failing test

```php
<?php
// tests/Unit/Sharing/SharingFacadeTest.php

namespace Tests\Unit\Sharing;

use AuthService\Helper\Sharing\Client\ShareResult;
use AuthService\Helper\Sharing\Exceptions\UserShareCollisionException;
use AuthService\Helper\Sharing\Facades\Sharing;
use AuthService\Helper\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class SharingFacadeTest extends TestCase
{
    public function test_share_user_returns_share_result_on_success(): void
    {
        Http::fake([
            '*/api/v1/auth/user-shares' => Http::response([
                'data' => ['id' => 'share_xyz', 'status' => 'active'],
            ], 201),
        ]);

        $result = Sharing::shareUser('user_1', 'svc_target', 'service_purchase', ['client']);

        $this->assertInstanceOf(ShareResult::class, $result);
        $this->assertEquals('share_xyz', $result->shareId);
    }

    public function test_share_user_throws_collision_exception_in_strict_mode(): void
    {
        Http::fake([
            '*/api/v1/auth/user-shares' => Http::response([
                'error' => 'conflict',
                'conflict' => [
                    'id' => 'conflict_1',
                    'source_user_id' => 'u1',
                    'target_existing_user_id' => 'u99',
                    'resolution_url' => '/api/v1/auth/user-share-conflicts/conflict_1',
                ],
            ], 409),
        ]);

        $this->expectException(UserShareCollisionException::class);
        Sharing::shareUser(
            userId: 'u1',
            targetServiceId: 'svc_target',
            intent: 'service_purchase',
            strictOnConflict: true,
        );
    }

    public function test_mint_handoff_token_delegates_to_handoff_client(): void
    {
        Http::fake([
            '*/api/v1/auth/handoff-tokens' => Http::response([
                'token' => 'raw_xyz',
                'redirect_url' => 'https://portify.app/auth/handoff?token=raw_xyz',
                'expires_at' => '2026-05-27T10:01:00Z',
            ], 200),
        ]);

        $handoff = Sharing::mintHandoffToken('share_xyz', '/cases/abc');

        $this->assertEquals('raw_xyz', $handoff->token);
    }

    public function test_revoke_share_delegates(): void
    {
        Http::fake([
            '*/api/v1/auth/user-shares/share_xyz' => Http::response([
                'data' => ['id' => 'share_xyz', 'status' => 'revoked'],
            ], 200),
        ]);

        $result = Sharing::revokeShare('share_xyz', reason: 'admission_withdrawn');
        $this->assertEquals('revoked', $result->status);
    }

    public function test_send_payload_throws_until_phase_e2_lands(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('SharingOutboxRepository');
        Sharing::sendPayload('share_xyz', 'service_purchase', ['order_id' => 'o1'], 'idem_1');
    }
}
```

### Step 2 — Run, expect failure

```bash
vendor/bin/pest tests/Unit/Sharing/SharingFacadeTest.php
```

Expected: class-not-found for `Sharing` facade / `SharingService`.

### Step 3 — Implement `SharingService` + facade alias + provider binding

```php
<?php
// src/Sharing/SharingService.php

namespace AuthService\Helper\Sharing;

use AuthService\Helper\Sharing\Client\HandoffMintResult;
use AuthService\Helper\Sharing\Client\HandoffTokenClient;
use AuthService\Helper\Sharing\Client\ShareResult;
use AuthService\Helper\Sharing\Client\UserShareClient;
use AuthService\Helper\Sharing\Exceptions\UserShareCollisionException;

class SharingService
{
    public function __construct(
        protected UserShareClient $userShares,
        protected HandoffTokenClient $handoffTokens,
    ) {}

    public function shareUser(
        string $userId,
        string $targetServiceId,
        string $intent,
        array $grantedRoles = [],
        array $metadata = [],
        bool $strictOnConflict = false,
    ): ShareResult {
        $result = $this->userShares->shareUser(
            userId: $userId,
            targetServiceId: $targetServiceId,
            intent: $intent,
            grantedRoles: $grantedRoles,
            metadata: $metadata,
        );

        if ($strictOnConflict && $result->conflict !== null) {
            throw UserShareCollisionException::fromConflict($result->conflict);
        }

        return $result;
    }

    public function mintHandoffToken(string $shareId, ?string $nextPath = null): HandoffMintResult
    {
        return $this->handoffTokens->mint($shareId, $nextPath);
    }

    public function revokeShare(string $shareId, ?string $reason = null): ShareResult
    {
        return $this->userShares->revoke($shareId, $reason);
    }

    public function resolveCollision(string $conflictId, string $strategy, array $params = []): array
    {
        return $this->userShares->resolveCollision($conflictId, $strategy, $params);
    }

    public function waitForMergeCompletion(string $conflictId, int $timeoutSeconds = 30): array
    {
        return $this->userShares->waitForMergeCompletion($conflictId, $timeoutSeconds);
    }

    // ──── Stubs filled in by later phases ────

    public function sendPayload(
        string $shareId,
        string $intent,
        array|object $payload,
        string $idempotencyKey,
    ): object {
        throw new \LogicException(
            'Sharing::sendPayload requires SharingOutboxRepository (Phase E2). Not yet wired.'
        );
    }

    public function redeliver(string $messageId): object
    {
        throw new \LogicException(
            'Sharing::redeliver requires the outbox + DLQ pipeline (Phase E5). Not yet wired.'
        );
    }

    public function lastInboundFor(string $shareId): ?object
    {
        throw new \LogicException(
            'Sharing::lastInboundFor requires the inbox read path (Phase D2c). Not yet wired.'
        );
    }
}
```

```php
<?php
// src/Sharing/Facades/Sharing.php

namespace AuthService\Helper\Sharing\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \AuthService\Helper\Sharing\Client\ShareResult shareUser(string $userId, string $targetServiceId, string $intent, array $grantedRoles = [], array $metadata = [], bool $strictOnConflict = false)
 * @method static \AuthService\Helper\Sharing\Client\HandoffMintResult mintHandoffToken(string $shareId, ?string $nextPath = null)
 * @method static \AuthService\Helper\Sharing\Client\ShareResult revokeShare(string $shareId, ?string $reason = null)
 * @method static array resolveCollision(string $conflictId, string $strategy, array $params = [])
 * @method static array waitForMergeCompletion(string $conflictId, int $timeoutSeconds = 30)
 *
 * @see \AuthService\Helper\Sharing\SharingService
 */
class Sharing extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \AuthService\Helper\Sharing\SharingService::class;
    }
}
```

### Step 4 — Wire in `SharingServiceProvider`

Edit `src/Sharing/SharingServiceProvider.php` — add inside `register()`:

```php
$this->app->singleton(\AuthService\Helper\Sharing\SharingService::class, function ($app) {
    return new \AuthService\Helper\Sharing\SharingService(
        $app->make(\AuthService\Helper\Sharing\Client\UserShareClient::class),
        $app->make(\AuthService\Helper\Sharing\Client\HandoffTokenClient::class),
    );
});
```

And inside `boot()` register the alias so `Sharing::method()` resolves without an explicit import path:

```php
$loader = \Illuminate\Foundation\AliasLoader::getInstance();
$loader->alias('Sharing', \AuthService\Helper\Sharing\Facades\Sharing::class);
```

### Step 5 — Run + commit

```bash
vendor/bin/pest tests/Unit/Sharing/SharingFacadeTest.php
# Expected: 5 passed.

git add src/Sharing/SharingService.php \
        src/Sharing/Facades/Sharing.php \
        src/Sharing/SharingServiceProvider.php \
        tests/Unit/Sharing/SharingFacadeTest.php
git commit -m "feat(sharing): phase C4 — Sharing facade wiring

Composes UserShareClient + HandoffTokenClient into one ergonomic
SharingService and exposes it via the Sharing facade. strictOnConflict
bubbles UserShareCollisionException on 409. sendPayload/redeliver/
lastInboundFor throw LogicException until D2c/E2/E5 ship — explicit
TODO trail.

Phase: C4 of docs/plans/InterProductCommunication-2026-05-27/"
```
