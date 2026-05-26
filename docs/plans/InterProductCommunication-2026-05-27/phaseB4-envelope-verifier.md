# Phase B4 — `EnvelopeVerifier`

**Repo:** `auth-service-helper`
**Spec section:** §5 + §13 (clock skew, key rotation)
**Depends on:** B2, B3

## Goal

Verify an inbound `X-Signature` against the canonical-JSON body, enforce ±5min replay window, and support BOTH current + previous secrets during rotation.

## Files

- **Create:** `src/Sharing/Envelope/EnvelopeVerifier.php`
- **Create:** `src/Sharing/Envelope/Exceptions/EnvelopeSignatureMismatchException.php`
- **Create:** `src/Sharing/Envelope/Exceptions/EnvelopeReplayWindowException.php`
- **Test:** `tests/Unit/Sharing/Envelope/EnvelopeVerifierTest.php`

## Steps

### Step 1 — Failing tests

```php
<?php
// tests/Unit/Sharing/Envelope/EnvelopeVerifierTest.php

namespace Tests\Unit\Sharing\Envelope;

use AuthService\Helper\Sharing\Envelope\EnvelopeSigner;
use AuthService\Helper\Sharing\Envelope\EnvelopeVerifier;
use AuthService\Helper\Sharing\Envelope\Exceptions\EnvelopeReplayWindowException;
use AuthService\Helper\Sharing\Envelope\Exceptions\EnvelopeSignatureMismatchException;
use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use PHPUnit\Framework\TestCase;

class EnvelopeVerifierTest extends TestCase
{
    public function test_verifies_signature_signed_with_current_secret(): void
    {
        $env = $this->fakeEnvelope();
        $signer = new EnvelopeSigner('current');
        $header = $signer->sign($env, at: 1700000000);

        $verifier = new EnvelopeVerifier(currentSecret: 'current', previousSecret: null, windowSeconds: 300);
        $verifier->verify($env->toCanonicalJson(), $header, now: 1700000010); // 10s later, ok
    }

    public function test_verifies_signature_signed_with_previous_secret_during_rotation(): void
    {
        $env = $this->fakeEnvelope();
        $header = (new EnvelopeSigner('old'))->sign($env, at: 1700000000);

        $verifier = new EnvelopeVerifier(currentSecret: 'new', previousSecret: 'old', windowSeconds: 300);
        $verifier->verify($env->toCanonicalJson(), $header, now: 1700000010);
    }

    public function test_rejects_outside_replay_window(): void
    {
        $env = $this->fakeEnvelope();
        $header = (new EnvelopeSigner('s'))->sign($env, at: 1700000000);

        $verifier = new EnvelopeVerifier('s', null, 300);
        $this->expectException(EnvelopeReplayWindowException::class);
        $verifier->verify($env->toCanonicalJson(), $header, now: 1700000301); // 301s = past window
    }

    public function test_rejects_bad_signature(): void
    {
        $env = $this->fakeEnvelope();
        $header = (new EnvelopeSigner('wrong'))->sign($env, at: 1700000000);

        $verifier = new EnvelopeVerifier('right', null, 300);
        $this->expectException(EnvelopeSignatureMismatchException::class);
        $verifier->verify($env->toCanonicalJson(), $header, now: 1700000010);
    }

    public function test_rejects_malformed_header(): void
    {
        $verifier = new EnvelopeVerifier('s', null, 300);
        $this->expectException(EnvelopeSignatureMismatchException::class);
        $verifier->verify('{}', 'garbage', now: 1700000000);
    }

    private function fakeEnvelope(): ShareEnvelope
    {
        return ShareEnvelope::fromArray([
            'envelope_version' => '1', 'message_id' => 'm', 'correlation_id' => 'c',
            'intent' => 'i', 'intent_version' => '1.0',
            'source_service_id' => '00000000-0000-0000-0000-000000000001',
            'target_service_id' => '00000000-0000-0000-0000-000000000002',
            'user_id' => '00000000-0000-0000-0000-000000000003',
            'idempotency_key' => 'k', 'issued_at' => '2026-05-27T10:00:00Z',
            'payload' => [],
        ]);
    }
}
```

### Step 2 — Run, expect failure

```bash
vendor/bin/pest tests/Unit/Sharing/Envelope/EnvelopeVerifierTest.php
```

### Step 3 — Implement exceptions + verifier

```php
<?php
// src/Sharing/Envelope/Exceptions/EnvelopeSignatureMismatchException.php
namespace AuthService\Helper\Sharing\Envelope\Exceptions;
class EnvelopeSignatureMismatchException extends \RuntimeException {}
```

```php
<?php
// src/Sharing/Envelope/Exceptions/EnvelopeReplayWindowException.php
namespace AuthService\Helper\Sharing\Envelope\Exceptions;
class EnvelopeReplayWindowException extends \RuntimeException {}
```

```php
<?php
// src/Sharing/Envelope/EnvelopeVerifier.php

namespace AuthService\Helper\Sharing\Envelope;

use AuthService\Helper\Sharing\Envelope\Exceptions\EnvelopeReplayWindowException;
use AuthService\Helper\Sharing\Envelope\Exceptions\EnvelopeSignatureMismatchException;

final class EnvelopeVerifier
{
    public function __construct(
        private readonly string $currentSecret,
        private readonly ?string $previousSecret = null,
        private readonly int $windowSeconds = 300,
    ) {}

    /**
     * Throws on any failure. Returns void on success.
     */
    public function verify(string $canonicalJson, string $signatureHeader, ?int $now = null): void
    {
        $now = $now ?? time();
        $parsed = $this->parseHeader($signatureHeader);

        $age = abs($now - $parsed['t']);
        if ($age > $this->windowSeconds) {
            throw new EnvelopeReplayWindowException(
                "Signature timestamp outside ±{$this->windowSeconds}s window (age={$age}s)"
            );
        }

        $signedPayload = $parsed['t'].'.'.$canonicalJson;

        $candidates = array_filter([$this->currentSecret, $this->previousSecret]);
        foreach ($candidates as $secret) {
            $expected = hash_hmac('sha256', $signedPayload, $secret);
            if (hash_equals($expected, $parsed['v1'])) {
                return; // success
            }
        }

        throw new EnvelopeSignatureMismatchException('No signing key matched signature');
    }

    private function parseHeader(string $header): array
    {
        if (!preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $header, $m)) {
            throw new EnvelopeSignatureMismatchException("Malformed signature header: {$header}");
        }
        return ['t' => (int) $m[1], 'v1' => $m[2]];
    }
}
```

### Step 4 — Run + commit

```bash
vendor/bin/pest tests/Unit/Sharing/Envelope/EnvelopeVerifierTest.php
# Expected: 5 passed.

git add src/Sharing/Envelope/ tests/Unit/Sharing/Envelope/
git commit -m "feat(sharing): phase B4 — EnvelopeVerifier with key rotation

Verifies HMAC signature against current+previous secrets (for rotation
windows), enforces ±5min replay window. Typed exceptions for the two
distinct failure modes.

Phase: B4 of docs/plans/InterProductCommunication-2026-05-27/"
```
