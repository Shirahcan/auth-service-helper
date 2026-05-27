# Phase E1 — PrepClient + Sharing facade additions

**Repo:** `auth-service-helper`
**Depends on:** C1, C3 (peer endpoints must exist), v1.3 EnvelopeSigner

## Goal

Orchestrator-side client. Three new facade methods:

- `Sharing::prepare(...)` — POSTs to peer's `/sharing/prep/prepare`
- `Sharing::promote(...)` — POSTs to peer's `/sharing/prep/{prep_id}/promote`
- `Sharing::prepStatus(...)` — GETs peer's `/sharing/prep/{prep_id}/status`

All three sign the request envelope with the configured peer's `signing_secret` (same machinery as v1.3 outbound payloads).

## Files

- **Create:** `src/Sharing/Prep/Client/PrepClient.php`
- **Create:** `src/Sharing/Prep/Client/PrepResult.php`
- **Create:** `src/Sharing/Prep/Client/PromoteResultDto.php` (separate from peer-side PromoteResult — this is the client's deserialised view)
- **Create:** `src/Sharing/Prep/Client/PrepStatus.php`
- **Modify:** `src/Sharing/SharingService.php` (add `prepare/promote/prepStatus` methods)
- **Test:** `tests/Unit/Sharing/Prep/PrepClientTest.php`

## Steps

### Step 1 — Failing test

```php
<?php
// tests/Unit/Sharing/Prep/PrepClientTest.php
namespace Tests\Unit\Sharing\Prep;

use AuthService\Helper\Sharing\Facades\Sharing;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class PrepClientTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\AuthService\Helper\AuthServiceHelperServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('authservice.sharing.source_service_id', '11111111-1111-1111-1111-111111111111');
        $app['config']->set('authservice.sharing.peers.portify', [
            'webhook_url'       => 'https://portify.test/api/v1/inbound/user-share',
            'prep_base_url'     => 'https://portify.test/api/v1/sharing/prep',
            'signing_secret'    => 'shh',
            'trust_key'         => 'tk',
            'target_service_id' => '22222222-2222-2222-2222-222222222222',
        ]);
    }

    public function test_prepare_returns_PrepResult(): void
    {
        Http::fake([
            'portify.test/api/v1/sharing/prep/prepare' => Http::response([
                'prep_id'    => 'p-abc',
                'embed_url'  => 'https://portify.test/sharing/embed/agreement_sign/p-abc',
                'expires_at' => '2026-05-28T12:00:00Z',
                'status'     => 'prepared',
            ], 200),
        ]);

        $result = Sharing::prepare(
            peerSlug: 'portify',
            intentSlug: 'agreement_sign',
            idempotencyKey: 'studendly:c1:visa-rep',
            sourceResource: ['type' => 'checkout', 'id' => 'c1'],
            studentData:    ['external_id' => 'stu_1', 'email' => 'j@x.com', 'name' => 'Jane'],
            payload:        ['agreement_slug' => 'visa-rep-agreement'],
            returnTo:       'https://studendly.test/done',
        );

        $this->assertEquals('p-abc', $result->prepId);
        $this->assertStringContainsString('agreement_sign/p-abc', $result->embedUrl);
        $this->assertEquals('prepared', $result->status);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            return $body['intent'] === 'prep.prepare'
                && $body['idempotency_key'] === 'studendly:c1:visa-rep'
                && $body['payload']['operation'] === 'prepare'
                && $body['payload']['intent_slug'] === 'agreement_sign'
                && $request->hasHeader('X-Signature')
                && $request->hasHeader('X-Trust-Key', 'tk');
        });
    }

    public function test_promote_returns_PromoteResultDto(): void
    {
        Http::fake([
            'portify.test/api/v1/sharing/prep/p-abc/promote' => Http::response([
                'permanent_resource_id' => 'perm-1',
                'state' => 'pending_activation',
            ], 200),
        ]);

        $result = Sharing::promote(
            peerSlug: 'portify',
            prepId: 'p-abc',
            shareId: 'sh-1',
            triggerProof: ['payment_id' => 'pay-1'],
            idempotencyKey: 'studendly:c1:visa-rep',
        );

        $this->assertEquals('perm-1', $result->permanentResourceId);
        $this->assertEquals('pending_activation', $result->state);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            return $body['payload']['operation'] === 'promote'
                && $body['payload']['share_id'] === 'sh-1';
        });
    }

    public function test_promote_idempotent_409_returns_existing_permanent(): void
    {
        Http::fake([
            'portify.test/api/v1/sharing/prep/p-abc/promote' => Http::response([
                'error' => 'already_promoted',
                'permanent_resource_id' => 'perm-1',
                'state' => 'active',
            ], 409),
        ]);

        $result = Sharing::promote(
            peerSlug: 'portify', prepId: 'p-abc', shareId: 'sh-1', triggerProof: [],
            idempotencyKey: 'k',
        );

        $this->assertEquals('perm-1', $result->permanentResourceId);
        $this->assertEquals('active', $result->state);
    }

    public function test_prep_status_reads(): void
    {
        Http::fake([
            'portify.test/api/v1/sharing/prep/p-abc/status' => Http::response([
                'prep_id' => 'p-abc',
                'intent'  => 'agreement_sign',
                'status'  => 'signed',
                'signed_at' => '2026-05-27T10:00:00Z',
                'promoted_at' => null,
                'expires_at' => '2026-05-30T10:00:00Z',
                'permanent_resource_id' => null,
            ], 200),
        ]);

        $status = Sharing::prepStatus(peerSlug: 'portify', prepId: 'p-abc');
        $this->assertEquals('signed', $status->status);
        $this->assertEquals('agreement_sign', $status->intent);
        $this->assertNotNull($status->signedAt);
    }
}
```

### Step 2 — Implement DTOs

```php
<?php
// src/Sharing/Prep/Client/PrepResult.php
namespace AuthService\Helper\Sharing\Prep\Client;

final class PrepResult
{
    public function __construct(
        public readonly string $prepId,
        public readonly string $embedUrl,
        public readonly ?\DateTimeImmutable $expiresAt,
        public readonly string $status,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            prepId:    $raw['prep_id'],
            embedUrl:  $raw['embed_url'],
            expiresAt: isset($raw['expires_at']) ? new \DateTimeImmutable($raw['expires_at']) : null,
            status:    $raw['status'] ?? 'prepared',
        );
    }
}
```

```php
<?php
// src/Sharing/Prep/Client/PromoteResultDto.php
namespace AuthService\Helper\Sharing\Prep\Client;

final class PromoteResultDto
{
    public function __construct(
        public readonly string $permanentResourceId,
        public readonly string $state,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            permanentResourceId: $raw['permanent_resource_id'],
            state: $raw['state'] ?? 'active',
        );
    }
}
```

```php
<?php
// src/Sharing/Prep/Client/PrepStatus.php
namespace AuthService\Helper\Sharing\Prep\Client;

final class PrepStatus
{
    public function __construct(
        public readonly string $prepId,
        public readonly string $intent,
        public readonly string $status,
        public readonly ?\DateTimeImmutable $signedAt,
        public readonly ?\DateTimeImmutable $promotedAt,
        public readonly ?\DateTimeImmutable $expiresAt,
        public readonly ?string $permanentResourceId,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            prepId: $raw['prep_id'],
            intent: $raw['intent'],
            status: $raw['status'],
            signedAt:   isset($raw['signed_at'])   ? new \DateTimeImmutable($raw['signed_at'])   : null,
            promotedAt: isset($raw['promoted_at']) ? new \DateTimeImmutable($raw['promoted_at']) : null,
            expiresAt:  isset($raw['expires_at'])  ? new \DateTimeImmutable($raw['expires_at'])  : null,
            permanentResourceId: $raw['permanent_resource_id'] ?? null,
        ];
    }
}
```

### Step 3 — Implement PrepClient

```php
<?php
// src/Sharing/Prep/Client/PrepClient.php
namespace AuthService\Helper\Sharing\Prep\Client;

use AuthService\Helper\Sharing\Envelope\EnvelopeSigner;
use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PrepClient
{
    public function prepare(
        string $peerSlug,
        string $intentSlug,
        string $idempotencyKey,
        array $sourceResource,
        array $studentData,
        array $payload,
        ?string $returnTo,
    ): PrepResult {
        $peer = $this->peerConfig($peerSlug);
        $envelope = $this->envelope(
            peer: $peer,
            intent: 'prep.prepare',
            idempotencyKey: $idempotencyKey,
            userId: (string) ($studentData['external_id'] ?? ''),
            payload: [
                'operation'       => 'prepare',
                'intent_slug'     => $intentSlug,
                'intent_version'  => '1.0',
                'source_resource' => $sourceResource,
                'student_data'    => $studentData,
                'payload'         => $payload,
                'return_to'       => $returnTo,
            ],
        );

        $body = $this->bodyAndHeaders($envelope, $peer);
        $resp = Http::withHeaders($body['headers'])
            ->withBody($body['raw'], 'application/json')
            ->post($this->prepUrl($peer, 'prepare'));

        if (!$resp->ok()) {
            throw new \RuntimeException("prepare failed: HTTP {$resp->status()} — {$resp->body()}");
        }
        return PrepResult::fromArray($resp->json());
    }

    public function promote(
        string $peerSlug,
        string $prepId,
        string $shareId,
        array $triggerProof,
        string $idempotencyKey,
    ): PromoteResultDto {
        $peer = $this->peerConfig($peerSlug);
        $envelope = $this->envelope(
            peer: $peer,
            intent: 'prep.promote',
            idempotencyKey: $idempotencyKey,
            userId: '',
            payload: [
                'operation'     => 'promote',
                'share_id'      => $shareId,
                'trigger_proof' => $triggerProof,
            ],
        );

        $body = $this->bodyAndHeaders($envelope, $peer);
        $resp = Http::withHeaders($body['headers'])
            ->withBody($body['raw'], 'application/json')
            ->post($this->prepUrl($peer, $prepId . '/promote'));

        // 409 already_promoted is INFORMATIONAL, not a hard error — return existing
        if ($resp->status() === 409 && ($resp->json('error') ?? null) === 'already_promoted') {
            return PromoteResultDto::fromArray($resp->json());
        }
        if (!$resp->ok()) {
            throw new \RuntimeException("promote failed: HTTP {$resp->status()} — {$resp->body()}");
        }
        return PromoteResultDto::fromArray($resp->json());
    }

    public function prepStatus(string $peerSlug, string $prepId): PrepStatus
    {
        $peer = $this->peerConfig($peerSlug);
        $envelope = $this->envelope(
            peer: $peer,
            intent: 'prep.status',
            idempotencyKey: 'status:' . $prepId,
            userId: '',
            payload: ['operation' => 'status'],
        );

        $body = $this->bodyAndHeaders($envelope, $peer);
        $resp = Http::withHeaders($body['headers'])
            ->withBody($body['raw'], 'application/json')
            ->post($this->prepUrl($peer, $prepId . '/status'));  // POST so HMAC body verification works

        if (!$resp->ok()) {
            throw new \RuntimeException("prepStatus failed: HTTP {$resp->status()} — {$resp->body()}");
        }
        return PrepStatus::fromArray($resp->json());
    }

    protected function peerConfig(string $slug): array
    {
        $peer = (array) config("authservice.sharing.peers.{$slug}", []);
        if (empty($peer)) {
            throw new \InvalidArgumentException("Unknown peer '{$slug}' in authservice.sharing.peers.*");
        }
        return $peer;
    }

    protected function envelope(array $peer, string $intent, string $idempotencyKey, string $userId, array $payload): ShareEnvelope
    {
        return ShareEnvelope::fromArray([
            'envelope_version' => '1',
            'message_id'       => 'msg_' . Str::ulid()->toBase32(),
            'correlation_id'   => $idempotencyKey,
            'intent'           => $intent,
            'intent_version'   => '1.0',
            'source_service_id'=> (string) config('authservice.sharing.source_service_id', ''),
            'target_service_id'=> (string) ($peer['target_service_id'] ?? ''),
            'user_id'          => $userId,
            'idempotency_key'  => $idempotencyKey,
            'issued_at'        => now()->toIso8601String(),
            'payload'          => $payload,
        ]);
    }

    /** @return array{raw:string, headers:array<string,string>} */
    protected function bodyAndHeaders(ShareEnvelope $envelope, array $peer): array
    {
        $raw = $envelope->toCanonicalJson();
        $signature = (new EnvelopeSigner((string) $peer['signing_secret']))->sign($envelope);
        return [
            'raw' => $raw,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Signature'  => $signature,
                'X-Trust-Key'  => (string) ($peer['trust_key'] ?? ''),
                'X-Idempotency-Key' => $envelope->idempotencyKey,
            ],
        ];
    }

    protected function prepUrl(array $peer, string $suffix): string
    {
        $base = $peer['prep_base_url'] ?? rtrim((string) $peer['webhook_url'], '/api/v1/inbound/user-share') . '/api/v1/sharing/prep';
        return rtrim($base, '/') . '/' . ltrim($suffix, '/');
    }
}
```

### Step 4 — Wire into SharingService facade

```php
// src/Sharing/SharingService.php — add 3 methods:
public function prepare(
    string $peerSlug, string $intentSlug, string $idempotencyKey,
    array $sourceResource, array $studentData, array $payload, ?string $returnTo = null,
): \AuthService\Helper\Sharing\Prep\Client\PrepResult {
    return app(\AuthService\Helper\Sharing\Prep\Client\PrepClient::class)
        ->prepare($peerSlug, $intentSlug, $idempotencyKey, $sourceResource, $studentData, $payload, $returnTo);
}

public function promote(
    string $peerSlug, string $prepId, string $shareId, array $triggerProof = [], ?string $idempotencyKey = null,
): \AuthService\Helper\Sharing\Prep\Client\PromoteResultDto {
    return app(\AuthService\Helper\Sharing\Prep\Client\PrepClient::class)
        ->promote($peerSlug, $prepId, $shareId, $triggerProof, $idempotencyKey ?? "promote:{$prepId}");
}

public function prepStatus(string $peerSlug, string $prepId): \AuthService\Helper\Sharing\Prep\Client\PrepStatus {
    return app(\AuthService\Helper\Sharing\Prep\Client\PrepClient::class)
        ->prepStatus($peerSlug, $prepId);
}
```

And the @method docblock on `src/Sharing/Facades/Sharing.php`.

### Step 5 — Run + commit

```bash
vendor/bin/phpunit tests/Unit/Sharing/Prep/PrepClientTest.php
# Expected: 4 passed.

git add src/Sharing/Prep/Client/ src/Sharing/SharingService.php src/Sharing/Facades/Sharing.php \
        tests/Unit/Sharing/Prep/PrepClientTest.php
git commit -m "feat(sharing): phase E1 — PrepClient + Sharing::prepare/promote/prepStatus

Orchestrator-side facade. Three new methods on Sharing reuse the v1.3
ShareEnvelope HMAC machinery to talk to the peer's /sharing/prep/* routes.
promote() treats 409 already_promoted as a SUCCESS (returns existing
permanent_resource_id) — idempotency is the spec.

Phase: E1 of docs/plans/PrepSignPromote-2026-05-27/"
```

## Verification checklist

- [ ] `Sharing::prepare()` returns PrepResult with embedUrl ready to drop into an iframe
- [ ] `Sharing::promote()` treats HTTP 409 `already_promoted` as success (not exception) — returns existing permanent_resource_id
- [ ] All three methods sign with the peer's `signing_secret`; verification on the peer side is identical to outbound payloads
- [ ] `prep_base_url` config falls back to deriving from `webhook_url` if not set
