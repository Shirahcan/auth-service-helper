# Phase C2b — `resolveCollision` + `waitForMergeCompletion`

**Repo:** `auth-service-helper`
**Spec section:** §11 "Collision Handling" (resolution strategies)
**Depends on:** C2a

## Goal

Write the resolution-side methods on `UserShareClient`: `resolveCollision($conflictId, $strategy, $params = [])` hits auth-service's resolution endpoint, and `waitForMergeCompletion($conflictId, $timeoutSeconds = 30)` polls until status is non-`pending`, returning the final conflict row. Throws `\RuntimeException` on timeout. Strategy enum is validated client-side to fail fast.

## Files

- **Modify:** `src/Sharing/Client/UserShareClient.php`
- **Test:** `tests/Unit/Sharing/Client/UserShareClientResolveTest.php`

## Steps

### Step 1 — Failing test

```php
<?php
// tests/Unit/Sharing/Client/UserShareClientResolveTest.php

namespace Tests\Unit\Sharing\Client;

use AuthService\Helper\Sharing\Client\UserShareClient;
use AuthService\Helper\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class UserShareClientResolveTest extends TestCase
{
    public function test_resolve_collision_posts_strategy_and_params(): void
    {
        Http::fake([
            '*/api/v1/auth/user-share-conflicts/conflict_1/resolve' => Http::response([
                'data' => ['id' => 'conflict_1', 'status' => 'resolved', 'strategy' => 'link_alias'],
            ], 200),
        ]);

        $client = $this->app->make(UserShareClient::class);
        $body = $client->resolveCollision(
            conflictId: 'conflict_1',
            strategy: 'link_alias',
            params: ['alias_user_id' => 'u99'],
        );

        $this->assertEquals('resolved', $body['status']);

        Http::assertSent(function ($r) {
            return $r->method() === 'POST'
                && str_ends_with($r->url(), '/conflict_1/resolve')
                && $r['strategy'] === 'link_alias'
                && $r['alias_user_id'] === 'u99';
        });
    }

    public function test_resolve_collision_rejects_unknown_strategy(): void
    {
        $client = $this->app->make(UserShareClient::class);

        $this->expectException(\InvalidArgumentException::class);
        $client->resolveCollision('conflict_1', 'teleport');
    }

    public function test_wait_for_merge_completion_returns_terminal_status(): void
    {
        Http::fakeSequence('*/api/v1/auth/user-share-conflicts/conflict_1')
            ->push(['data' => ['id' => 'conflict_1', 'status' => 'pending']], 200)
            ->push(['data' => ['id' => 'conflict_1', 'status' => 'pending']], 200)
            ->push(['data' => ['id' => 'conflict_1', 'status' => 'resolved']], 200);

        $client = $this->app->make(UserShareClient::class);
        $final = $client->waitForMergeCompletion('conflict_1', timeoutSeconds: 5, pollMillis: 10);

        $this->assertEquals('resolved', $final['status']);
    }

    public function test_wait_for_merge_completion_throws_on_timeout(): void
    {
        Http::fake([
            '*/api/v1/auth/user-share-conflicts/conflict_1' => Http::response([
                'data' => ['id' => 'conflict_1', 'status' => 'pending'],
            ], 200),
        ]);

        $client = $this->app->make(UserShareClient::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Timed out waiting for conflict_1');
        $client->waitForMergeCompletion('conflict_1', timeoutSeconds: 1, pollMillis: 10);
    }
}
```

### Step 2 — Run, expect failure

```bash
vendor/bin/pest tests/Unit/Sharing/Client/UserShareClientResolveTest.php
```

Expected: `BadMethodCallException` for `resolveCollision`/`waitForMergeCompletion`.

### Step 3 — Implement on `UserShareClient`

Append:

```php
public const RESOLUTION_STRATEGIES = [
    'cancel',
    'reject_distinct',
    'link_alias',
    'merge_into_target',
    'merge_into_source',
];

public function resolveCollision(string $conflictId, string $strategy, array $params = []): array
{
    if (!in_array($strategy, self::RESOLUTION_STRATEGIES, true)) {
        throw new \InvalidArgumentException(
            "Unknown resolution strategy '{$strategy}'. "
            ."Valid: ".implode(', ', self::RESOLUTION_STRATEGIES)
        );
    }

    $body = array_merge($params, ['strategy' => $strategy]);
    $response = $this->http()->post(
        $this->url('user-share-conflicts/'.$conflictId.'/resolve'),
        $body,
    );
    $response->throw();
    return $response->json('data') ?? $response->json() ?? [];
}

/**
 * Polls auth-service until the conflict has a non-pending status, or throws on timeout.
 *
 * @param int $pollMillis ms between polls (default 250)
 */
public function waitForMergeCompletion(
    string $conflictId,
    int $timeoutSeconds = 30,
    int $pollMillis = 250,
): array {
    $deadline = microtime(true) + $timeoutSeconds;

    while (microtime(true) < $deadline) {
        $ref = $this->fetchConflictRow($conflictId);
        $status = $ref['status'] ?? 'pending';
        if ($status !== 'pending') {
            return $ref;
        }
        usleep($pollMillis * 1000);
    }

    throw new \RuntimeException("Timed out waiting for {$conflictId} to leave pending status");
}

protected function fetchConflictRow(string $conflictId): array
{
    $response = $this->http()->get($this->url('user-share-conflicts/'.$conflictId));
    $response->throw();
    return $response->json('data') ?? $response->json() ?? [];
}
```

### Step 4 — No additional wiring

Already bound from C1a.

### Step 5 — Run + commit

```bash
vendor/bin/pest tests/Unit/Sharing/Client/UserShareClientResolveTest.php
# Expected: 4 passed.

git add src/Sharing/Client/UserShareClient.php tests/Unit/Sharing/Client/UserShareClientResolveTest.php
git commit -m "feat(sharing): phase C2b — resolveCollision + waitForMergeCompletion

resolveCollision posts the chosen strategy (validated client-side
against the 5 supported values) and any extra params to auth-service.
waitForMergeCompletion polls the conflict row at a configurable
cadence until status leaves pending, throwing RuntimeException on
timeout.

Phase: C2b of docs/plans/InterProductCommunication-2026-05-27/"
```
