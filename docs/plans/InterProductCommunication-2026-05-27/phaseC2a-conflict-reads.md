# Phase C2a — Conflict reads + `UserShareCollisionException`

**Repo:** `auth-service-helper`
**Spec section:** §11 "Collision Handling"
**Depends on:** C1a

## Goal

Add the read-side of the conflict surface: `listConflicts(?array $filters = [])` and `getConflict(string $conflictId)` on `UserShareClient`, plus the typed `UserShareCollisionException` that Phase C4's `Sharing::shareUser` throws when a product enables strict mode. The exception carries everything a caller needs to either show the conflict to a human or auto-resolve it.

## Files

- **Modify:** `src/Sharing/Client/UserShareClient.php`
- **Create:** `src/Sharing/Exceptions/UserShareCollisionException.php`
- **Test:** `tests/Unit/Sharing/Client/UserShareClientConflictReadsTest.php`

## Steps

### Step 1 — Failing test

```php
<?php
// tests/Unit/Sharing/Client/UserShareClientConflictReadsTest.php

namespace Tests\Unit\Sharing\Client;

use AuthService\Helper\Sharing\Client\ConflictRef;
use AuthService\Helper\Sharing\Client\UserShareClient;
use AuthService\Helper\Sharing\Exceptions\UserShareCollisionException;
use AuthService\Helper\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class UserShareClientConflictReadsTest extends TestCase
{
    public function test_list_conflicts_returns_collection_of_conflict_refs(): void
    {
        Http::fake([
            '*/api/v1/auth/user-share-conflicts*' => Http::response([
                'data' => [
                    [
                        'id' => 'conflict_1',
                        'source_user_id' => 'u1',
                        'target_existing_user_id' => 'u99',
                        'resolution_url' => '/api/v1/auth/user-share-conflicts/conflict_1',
                    ],
                    [
                        'id' => 'conflict_2',
                        'source_user_id' => 'u2',
                        'target_existing_user_id' => null,
                        'resolution_url' => '/api/v1/auth/user-share-conflicts/conflict_2',
                    ],
                ],
            ], 200),
        ]);

        $client = $this->app->make(UserShareClient::class);
        $rows = $client->listConflicts(['status' => 'pending']);

        $this->assertCount(2, $rows);
        $this->assertContainsOnlyInstancesOf(ConflictRef::class, $rows);
        $this->assertEquals('conflict_1', $rows[0]->conflictId);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'status=pending'));
    }

    public function test_get_conflict_returns_single_ref(): void
    {
        Http::fake([
            '*/api/v1/auth/user-share-conflicts/conflict_1' => Http::response([
                'data' => [
                    'id' => 'conflict_1',
                    'source_user_id' => 'u1',
                    'target_existing_user_id' => 'u99',
                    'resolution_url' => '/api/v1/auth/user-share-conflicts/conflict_1',
                ],
            ], 200),
        ]);

        $client = $this->app->make(UserShareClient::class);
        $ref = $client->getConflict('conflict_1');

        $this->assertEquals('conflict_1', $ref->conflictId);
        $this->assertEquals('u99', $ref->targetExistingUserId);
    }

    public function test_collision_exception_carries_conflict_metadata(): void
    {
        $ref = new ConflictRef(
            conflictId: 'conflict_1',
            sourceUserId: 'u1',
            targetExistingUserId: 'u99',
            resolutionUrl: '/api/v1/auth/user-share-conflicts/conflict_1',
        );

        $e = UserShareCollisionException::fromConflict($ref);

        $this->assertEquals('conflict_1', $e->conflictId);
        $this->assertEquals('u1', $e->sourceUserId);
        $this->assertEquals('u99', $e->targetExistingUserId);
        $this->assertEquals('/api/v1/auth/user-share-conflicts/conflict_1', $e->resolutionUrl);
        $this->assertStringContainsString('conflict_1', $e->getMessage());
    }
}
```

### Step 2 — Run, expect failure

```bash
vendor/bin/pest tests/Unit/Sharing/Client/UserShareClientConflictReadsTest.php
```

Expected: class-not-found for `UserShareCollisionException`; missing methods on `UserShareClient`.

### Step 3 — Implement the exception + client reads

```php
<?php
// src/Sharing/Exceptions/UserShareCollisionException.php

namespace AuthService\Helper\Sharing\Exceptions;

use AuthService\Helper\Sharing\Client\ConflictRef;
use RuntimeException;

class UserShareCollisionException extends RuntimeException
{
    public function __construct(
        public readonly string $conflictId,
        public readonly ?string $sourceUserId,
        public readonly ?string $targetExistingUserId,
        public readonly ?string $resolutionUrl,
        string $message = '',
    ) {
        parent::__construct(
            $message !== ''
                ? $message
                : "User-share collision (conflict_id={$conflictId}); resolve before retrying."
        );
    }

    public static function fromConflict(ConflictRef $ref): self
    {
        return new self(
            conflictId: $ref->conflictId,
            sourceUserId: $ref->sourceUserId,
            targetExistingUserId: $ref->targetExistingUserId,
            resolutionUrl: $ref->resolutionUrl,
        );
    }
}
```

Append to `UserShareClient`:

```php
/**
 * @return array<int, \AuthService\Helper\Sharing\Client\ConflictRef>
 */
public function listConflicts(array $filters = []): array
{
    $response = $this->http()->get($this->url('user-share-conflicts'), $filters);
    $response->throw();
    $rows = $response->json('data') ?? [];
    return array_map(fn (array $row) => ConflictRef::fromArray($row), $rows);
}

public function getConflict(string $conflictId): ConflictRef
{
    $response = $this->http()->get($this->url('user-share-conflicts/'.$conflictId));
    $response->throw();
    return ConflictRef::fromArray($response->json('data') ?? $response->json() ?? []);
}
```

### Step 4 — No additional wiring

`UserShareClient` is already singleton-bound; the exception is auto-loaded by composer's PSR-4.

### Step 5 — Run + commit

```bash
vendor/bin/pest tests/Unit/Sharing/Client/UserShareClientConflictReadsTest.php
# Expected: 3 passed.

git add src/Sharing/Client/UserShareClient.php \
        src/Sharing/Exceptions/UserShareCollisionException.php \
        tests/Unit/Sharing/Client/UserShareClientConflictReadsTest.php
git commit -m "feat(sharing): phase C2a — conflict reads + collision exception

listConflicts/getConflict wrap the auth-service collision read endpoints
and hydrate ConflictRef instances. New typed
UserShareCollisionException carries conflictId/source/existing/
resolution_url so callers can show the conflict to a human or call
resolveCollision (C2b) directly.

Phase: C2a of docs/plans/InterProductCommunication-2026-05-27/"
```
