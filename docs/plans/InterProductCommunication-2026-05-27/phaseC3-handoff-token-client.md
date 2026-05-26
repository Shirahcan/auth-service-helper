# Phase C3 — `HandoffTokenClient` (mint + exchange)

**Repo:** `auth-service-helper`
**Spec section:** §4 (auth-service endpoints) + §7 (source-side API)
**Depends on:** A3 (mint endpoint), A4 (exchange endpoint), B1 (ServiceProvider)

## Goal

Wrap auth-service's two new handoff endpoints in a typed PHP client. `mint()` is called from the SOURCE service at CTA-click time and returns `{token, redirectUrl, expiresAt}`. `exchange()` is called from the DESTINATION service when it receives the redirect; it returns the resolved user + a short-lived session token, or throws `HandoffTokenInvalidException` with a subkind enum (`consumed | expired | wrong_target | unknown`).

## Files

- **Create:** `src/Sharing/Client/HandoffTokenClient.php`
- **Create:** `src/Sharing/Client/HandoffMintResult.php`
- **Create:** `src/Sharing/Client/HandoffExchangeResult.php`
- **Create:** `src/Sharing/Exceptions/HandoffTokenInvalidException.php`
- **Test:** `tests/Unit/Sharing/Client/HandoffTokenClientTest.php`

## Steps

### Step 1 — Failing test

```php
<?php
// tests/Unit/Sharing/Client/HandoffTokenClientTest.php

namespace Tests\Unit\Sharing\Client;

use AuthService\Helper\Sharing\Client\HandoffExchangeResult;
use AuthService\Helper\Sharing\Client\HandoffMintResult;
use AuthService\Helper\Sharing\Client\HandoffTokenClient;
use AuthService\Helper\Sharing\Exceptions\HandoffTokenInvalidException;
use AuthService\Helper\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class HandoffTokenClientTest extends TestCase
{
    public function test_mint_returns_handoff_mint_result(): void
    {
        Http::fake([
            '*/api/v1/auth/handoff-tokens' => Http::response([
                'token' => 'raw_token_xyz',
                'redirect_url' => 'https://portify.app/auth/handoff?token=raw_token_xyz&next=%2Fcases%2Fabc',
                'expires_at' => '2026-05-27T10:01:00Z',
            ], 200),
        ]);

        $client = $this->app->make(HandoffTokenClient::class);
        $result = $client->mint(shareId: 'share_xyz', nextPath: '/cases/abc');

        $this->assertInstanceOf(HandoffMintResult::class, $result);
        $this->assertEquals('raw_token_xyz', $result->token);
        $this->assertStringContainsString('/auth/handoff?token=raw_token_xyz', $result->redirectUrl);
        $this->assertEquals('2026-05-27T10:01:00Z', $result->expiresAt);

        Http::assertSent(function ($r) {
            return $r->method() === 'POST'
                && str_ends_with($r->url(), '/api/v1/auth/handoff-tokens')
                && $r['share_id'] === 'share_xyz'
                && $r['next_path'] === '/cases/abc'
                && $r->hasHeader('X-API-Key');
        });
    }

    public function test_exchange_returns_user_and_session_token(): void
    {
        Http::fake([
            '*/api/v1/auth/handoff-tokens/raw_token_xyz/exchange' => Http::response([
                'user' => ['id' => 'u1', 'uuid' => 'uuid_u1', 'name' => 'Alice'],
                'session_token' => 'sess_short_lived',
                'share_id' => 'share_xyz',
                'next_path' => '/cases/abc',
            ], 200),
        ]);

        $client = $this->app->make(HandoffTokenClient::class);
        $result = $client->exchange('raw_token_xyz');

        $this->assertInstanceOf(HandoffExchangeResult::class, $result);
        $this->assertEquals('uuid_u1', $result->user['uuid']);
        $this->assertEquals('sess_short_lived', $result->sessionToken);
        $this->assertEquals('share_xyz', $result->shareId);
        $this->assertEquals('/cases/abc', $result->nextPath);
    }

    public function test_exchange_throws_for_consumed_token(): void
    {
        Http::fake([
            '*/api/v1/auth/handoff-tokens/*/exchange' => Http::response([
                'error' => 'handoff_token_consumed',
            ], 410),
        ]);

        $client = $this->app->make(HandoffTokenClient::class);

        try {
            $client->exchange('raw_token_xyz');
            $this->fail('Expected HandoffTokenInvalidException');
        } catch (HandoffTokenInvalidException $e) {
            $this->assertEquals('consumed', $e->reason);
        }
    }

    public function test_exchange_throws_for_wrong_target(): void
    {
        Http::fake([
            '*/api/v1/auth/handoff-tokens/*/exchange' => Http::response([
                'error' => 'handoff_token_target_mismatch',
            ], 403),
        ]);

        $client = $this->app->make(HandoffTokenClient::class);

        try {
            $client->exchange('raw_token_xyz');
            $this->fail('Expected HandoffTokenInvalidException');
        } catch (HandoffTokenInvalidException $e) {
            $this->assertEquals('wrong_target', $e->reason);
        }
    }

    public function test_exchange_throws_unknown_for_404(): void
    {
        Http::fake([
            '*/api/v1/auth/handoff-tokens/*/exchange' => Http::response(['error' => 'not_found'], 404),
        ]);

        $client = $this->app->make(HandoffTokenClient::class);
        $this->expectException(HandoffTokenInvalidException::class);
        $client->exchange('nope');
    }
}
```

### Step 2 — Run, expect failure

```bash
vendor/bin/pest tests/Unit/Sharing/Client/HandoffTokenClientTest.php
```

### Step 3 — Implement the client + DTOs + exception

```php
<?php
// src/Sharing/Client/HandoffMintResult.php

namespace AuthService\Helper\Sharing\Client;

final class HandoffMintResult
{
    public function __construct(
        public readonly string $token,
        public readonly string $redirectUrl,
        public readonly string $expiresAt,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            token: $raw['token'],
            redirectUrl: $raw['redirect_url'],
            expiresAt: $raw['expires_at'],
        );
    }
}
```

```php
<?php
// src/Sharing/Client/HandoffExchangeResult.php

namespace AuthService\Helper\Sharing\Client;

final class HandoffExchangeResult
{
    public function __construct(
        public readonly array $user,
        public readonly string $sessionToken,
        public readonly string $shareId,
        public readonly ?string $nextPath,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            user: $raw['user'] ?? [],
            sessionToken: $raw['session_token'],
            shareId: $raw['share_id'],
            nextPath: $raw['next_path'] ?? null,
        );
    }
}
```

```php
<?php
// src/Sharing/Exceptions/HandoffTokenInvalidException.php

namespace AuthService\Helper\Sharing\Exceptions;

use RuntimeException;

class HandoffTokenInvalidException extends RuntimeException
{
    public const REASON_CONSUMED = 'consumed';
    public const REASON_EXPIRED = 'expired';
    public const REASON_WRONG_TARGET = 'wrong_target';
    public const REASON_UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $reason,
        public readonly int $httpStatus,
        string $message = '',
    ) {
        parent::__construct(
            $message !== '' ? $message : "Handoff token invalid ({$reason}, HTTP {$httpStatus})"
        );
    }

    public static function fromResponse(int $status, ?string $errorCode): self
    {
        $reason = match (true) {
            $errorCode === 'handoff_token_consumed' => self::REASON_CONSUMED,
            $errorCode === 'handoff_token_expired' => self::REASON_EXPIRED,
            $errorCode === 'handoff_token_target_mismatch' => self::REASON_WRONG_TARGET,
            $status === 410 => self::REASON_CONSUMED,
            $status === 403 => self::REASON_WRONG_TARGET,
            $status === 408 => self::REASON_EXPIRED,
            default => self::REASON_UNKNOWN,
        };
        return new self(reason: $reason, httpStatus: $status);
    }
}
```

```php
<?php
// src/Sharing/Client/HandoffTokenClient.php

namespace AuthService\Helper\Sharing\Client;

use AuthService\Helper\Sharing\Exceptions\HandoffTokenInvalidException;
use Illuminate\Support\Facades\Http;

class HandoffTokenClient
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

    public function mint(string $shareId, ?string $nextPath = null): HandoffMintResult
    {
        $payload = ['share_id' => $shareId];
        if ($nextPath !== null) {
            $payload['next_path'] = $nextPath;
        }

        $response = $this->http()->post($this->url('handoff-tokens'), $payload);
        $response->throw();
        return HandoffMintResult::fromArray($response->json());
    }

    public function exchange(string $rawToken): HandoffExchangeResult
    {
        $response = $this->http()->post(
            $this->url('handoff-tokens/'.urlencode($rawToken).'/exchange'),
            [],
        );

        if (!$response->successful()) {
            throw HandoffTokenInvalidException::fromResponse(
                status: $response->status(),
                errorCode: $response->json('error'),
            );
        }

        return HandoffExchangeResult::fromArray($response->json());
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

```php
$this->app->singleton(\AuthService\Helper\Sharing\Client\HandoffTokenClient::class);
```

### Step 5 — Run + commit

```bash
vendor/bin/pest tests/Unit/Sharing/Client/HandoffTokenClientTest.php
# Expected: 5 passed.

git add src/Sharing/Client/HandoffTokenClient.php \
        src/Sharing/Client/HandoffMintResult.php \
        src/Sharing/Client/HandoffExchangeResult.php \
        src/Sharing/Exceptions/HandoffTokenInvalidException.php \
        src/Sharing/SharingServiceProvider.php \
        tests/Unit/Sharing/Client/HandoffTokenClientTest.php
git commit -m "feat(sharing): phase C3 — HandoffTokenClient (mint + exchange)

mint() wraps POST /handoff-tokens; exchange() wraps
POST /handoff-tokens/{token}/exchange and throws
HandoffTokenInvalidException with a typed reason (consumed | expired |
wrong_target | unknown) so destinations can route to the right
/login?error=... page.

Phase: C3 of docs/plans/InterProductCommunication-2026-05-27/"
```
