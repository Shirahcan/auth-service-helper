# Phase B3 — `EnvelopeSigner` (HMAC-SHA256, ±5min window)

**Repo:** `auth-service-helper`
**Spec section:** §5 + §13 ("Trust key rotated mid-flight")
**Depends on:** B2

## Goal

Sign a `ShareEnvelope` for outbound delivery. Produces the `X-Signature: t=<unix>,v1=<hex>` header value. Supports configurable current secret (and the verifier in B4 will support BOTH current + previous for rotation).

## Files

- **Create:** `src/Sharing/Envelope/EnvelopeSigner.php`
- **Test:** `tests/Unit/Sharing/Envelope/EnvelopeSignerTest.php`

## Steps

### Step 1 — Write the failing signer test

```php
<?php
// tests/Unit/Sharing/Envelope/EnvelopeSignerTest.php

namespace Tests\Unit\Sharing\Envelope;

use AuthService\Helper\Sharing\Envelope\EnvelopeSigner;
use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use PHPUnit\Framework\TestCase;

class EnvelopeSignerTest extends TestCase
{
    public function test_sign_produces_header_value_with_t_and_v1(): void
    {
        $signer = new EnvelopeSigner(secret: 'shh');
        $env = $this->fakeEnvelope();
        $header = $signer->sign($env, at: 1700000000);
        $this->assertMatchesRegularExpression(
            '/^t=1700000000,v1=[a-f0-9]{64}$/',
            $header
        );
    }

    public function test_sign_is_deterministic_for_same_envelope_and_time(): void
    {
        $signer = new EnvelopeSigner(secret: 'shh');
        $env = $this->fakeEnvelope();
        $this->assertEquals(
            $signer->sign($env, at: 1700000000),
            $signer->sign($env, at: 1700000000),
        );
    }

    public function test_sign_changes_when_secret_changes(): void
    {
        $env = $this->fakeEnvelope();
        $this->assertNotEquals(
            (new EnvelopeSigner('a'))->sign($env, at: 1700000000),
            (new EnvelopeSigner('b'))->sign($env, at: 1700000000),
        );
    }

    public function test_sign_uses_canonical_json_so_payload_key_order_doesnt_matter(): void
    {
        $signer = new EnvelopeSigner(secret: 'shh');
        $env1 = ShareEnvelope::fromArray($this->raw(['payload' => ['a' => 1, 'b' => 2]]));
        $env2 = ShareEnvelope::fromArray($this->raw(['payload' => ['a' => 1, 'b' => 2]]));
        $this->assertEquals(
            $signer->sign($env1, at: 1700000000),
            $signer->sign($env2, at: 1700000000),
        );
    }

    private function fakeEnvelope(): ShareEnvelope
    {
        return ShareEnvelope::fromArray($this->raw());
    }

    private function raw(array $overrides = []): array
    {
        return array_merge([
            'envelope_version' => '1',
            'message_id' => 'msg_01HZ',
            'correlation_id' => 'share_01HX',
            'intent' => 'service_purchase',
            'intent_version' => '1.0',
            'source_service_id' => '00000000-0000-0000-0000-000000000001',
            'target_service_id' => '00000000-0000-0000-0000-000000000002',
            'user_id' => '00000000-0000-0000-0000-000000000003',
            'idempotency_key' => 'studendly:order:88421',
            'issued_at' => '2026-05-27T10:00:00Z',
            'payload' => ['order_id' => 'x'],
        ], $overrides);
    }
}
```

### Step 2 — Run, expect failure

```bash
cd C:\Users\benpl\Documents\GitHub\auth-service-helper
vendor/bin/pest tests/Unit/Sharing/Envelope/EnvelopeSignerTest.php
```

### Step 3 — Implement the signer

```php
<?php
// src/Sharing/Envelope/EnvelopeSigner.php

namespace AuthService\Helper\Sharing\Envelope;

final class EnvelopeSigner
{
    public function __construct(private readonly string $secret) {}

    /**
     * Returns the header value for X-Signature:
     *    t=<unix-seconds>,v1=<hex-hmac-sha256>
     *
     * HMAC input is "{t}.{canonical_json}" — the same shape used by
     * Stripe-style signature schemes so the format is familiar.
     */
    public function sign(ShareEnvelope $envelope, ?int $at = null): string
    {
        $t = $at ?? time();
        $signedPayload = $t.'.'.$envelope->toCanonicalJson();
        $sig = hash_hmac('sha256', $signedPayload, $this->secret);
        return "t={$t},v1={$sig}";
    }
}
```

### Step 4 — Run, expect pass + commit

```bash
vendor/bin/pest tests/Unit/Sharing/Envelope/EnvelopeSignerTest.php
# Expected: 4 passed.

git add src/Sharing/Envelope/EnvelopeSigner.php tests/Unit/Sharing/Envelope/EnvelopeSignerTest.php
git commit -m "feat(sharing): phase B3 — EnvelopeSigner (HMAC-SHA256)

X-Signature header value of shape 't=<unix>,v1=<hex>'. Deterministic
for fixed (envelope, secret, timestamp). Canonical JSON ensures key
order doesn't affect signature.

Phase: B3 of docs/plans/InterProductCommunication-2026-05-27/"
```
