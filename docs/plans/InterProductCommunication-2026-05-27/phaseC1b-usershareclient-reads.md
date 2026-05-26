# Phase C1b — `UserShareClient` reads: list + get

**Repo:** `auth-service-helper`
**Spec section:** §7 "Source-Side API"
**Depends on:** C1a

## Goal

Add `listOutgoing()`, `listIncoming()`, and `get($shareId)` to the existing `UserShareClient`. These wrap the GET endpoints on auth-service. Results are hydrated as `ShareResult` collections; paginated endpoints return a paginator-shaped DTO (preserving `meta` for downstream paging).

## Files

- **Modify:** `src/Sharing/Client/UserShareClient.php`
- **Create:** `src/Sharing/Client/SharePage.php` (paginator-shaped DTO)
- **Test:** `tests/Unit/Sharing/Client/UserShareClientReadsTest.php`

## Steps

### Step 1 — Failing test

```php
<?php
// tests/Unit/Sharing/Client/UserShareClientReadsTest.php

namespace Tests\Unit\Sharing\Client;

use AuthService\Helper\Sharing\Client\SharePage;
use AuthService\Helper\Sharing\Client\ShareResult;
use AuthService\Helper\Sharing\Client\UserShareClient;
use AuthService\Helper\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class UserShareClientReadsTest extends TestCase
{
    public function test_list_outgoing_returns_share_page(): void
    {
        Http::fake([
            '*/api/v1/auth/user-shares/outgoing*' => Http::response([
                'data' => [
                    ['id' => 'share_a', 'status' => 'active'],
                    ['id' => 'share_b', 'status' => 'revoked'],
                ],
                'meta' => ['current_page' => 1, 'per_page' => 50, 'total' => 2],
            ], 200),
        ]);

        $client = $this->app->make(UserShareClient::class);
        $page = $client->listOutgoing(filters: ['status' => 'active'], perPage: 50);

        $this->assertInstanceOf(SharePage::class, $page);
        $this->assertCount(2, $page->items);
        $this->assertContainsOnlyInstancesOf(ShareResult::class, $page->items);
        $this->assertEquals('share_a', $page->items[0]->shareId);
        $this->assertEquals(2, $page->total);

        Http::assertSent(function ($r) {
            return str_contains($r->url(), '/api/v1/auth/user-shares/outgoing')
                && str_contains($r->url(), 'status=active')
                && str_contains($r->url(), 'per_page=50');
        });
    }

    public function test_list_incoming_returns_share_page(): void
    {
        Http::fake([
            '*/api/v1/auth/user-shares/incoming*' => Http::response([
                'data' => [['id' => 'share_in_1', 'status' => 'active']],
                'meta' => ['current_page' => 1, 'per_page' => 50, 'total' => 1],
            ], 200),
        ]);

        $client = $this->app->make(UserShareClient::class);
        $page = $client->listIncoming();

        $this->assertCount(1, $page->items);
        $this->assertEquals('share_in_1', $page->items[0]->shareId);
    }

    public function test_get_returns_single_share_result(): void
    {
        Http::fake([
            '*/api/v1/auth/user-shares/share_xyz' => Http::response([
                'data' => ['id' => 'share_xyz', 'status' => 'active', 'intent' => 'service_purchase'],
            ], 200),
        ]);

        $client = $this->app->make(UserShareClient::class);
        $share = $client->get('share_xyz');

        $this->assertInstanceOf(ShareResult::class, $share);
        $this->assertEquals('share_xyz', $share->shareId);
        $this->assertEquals('service_purchase', $share->intent);
    }
}
```

### Step 2 — Run, expect failure

```bash
vendor/bin/pest tests/Unit/Sharing/Client/UserShareClientReadsTest.php
```

Expected: `BadMethodCallException` for `listOutgoing` / class-not-found for `SharePage`.

### Step 3 — Implement `SharePage` and the three methods

```php
<?php
// src/Sharing/Client/SharePage.php

namespace AuthService\Helper\Sharing\Client;

final class SharePage
{
    /**
     * @param array<int, ShareResult> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $currentPage = 1,
        public readonly int $perPage = 50,
        public readonly int $total = 0,
    ) {}

    public static function fromResponse(array $body): self
    {
        $items = array_map(
            fn (array $row) => ShareResult::fromArray($row),
            $body['data'] ?? [],
        );
        $meta = $body['meta'] ?? [];
        return new self(
            items: $items,
            currentPage: (int) ($meta['current_page'] ?? 1),
            perPage: (int) ($meta['per_page'] ?? count($items)),
            total: (int) ($meta['total'] ?? count($items)),
        );
    }
}
```

Append these methods to `UserShareClient`:

```php
public function listOutgoing(array $filters = [], int $perPage = 50): SharePage
{
    return $this->fetchPage('user-shares/outgoing', $filters, $perPage);
}

public function listIncoming(array $filters = [], int $perPage = 50): SharePage
{
    return $this->fetchPage('user-shares/incoming', $filters, $perPage);
}

public function get(string $shareId): ShareResult
{
    $response = $this->http()->get($this->url('user-shares/'.$shareId));
    $response->throw();
    return ShareResult::fromArray($response->json('data') ?? $response->json() ?? []);
}

protected function fetchPage(string $path, array $filters, int $perPage): SharePage
{
    $query = array_merge($filters, ['per_page' => $perPage]);
    $response = $this->http()->get($this->url($path), $query);
    $response->throw();
    return SharePage::fromResponse($response->json() ?? []);
}
```

### Step 4 — No additional wiring

`UserShareClient` is already bound as a singleton from C1a; no provider changes needed.

### Step 5 — Run + commit

```bash
vendor/bin/pest tests/Unit/Sharing/Client/UserShareClientReadsTest.php
# Expected: 3 passed.

git add src/Sharing/Client/UserShareClient.php src/Sharing/Client/SharePage.php tests/Unit/Sharing/Client/UserShareClientReadsTest.php
git commit -m "feat(sharing): phase C1b — UserShareClient list/get

listOutgoing/listIncoming/get wrap the auth-service GET endpoints
and hydrate ShareResult instances. List endpoints return a SharePage
that preserves pagination meta so callers can drive paging.

Phase: C1b of docs/plans/InterProductCommunication-2026-05-27/"
```
