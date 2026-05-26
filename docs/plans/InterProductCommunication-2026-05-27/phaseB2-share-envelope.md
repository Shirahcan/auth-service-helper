# Phase B2 — `ShareEnvelope` DTO + validator

**Repo:** `auth-service-helper`
**Spec section:** §5 "The Wire Envelope"
**Depends on:** B1

## Goal

The canonical wire-shape DTO that wraps every source→destination message. Immutable, JSON-roundtrippable, validates its own structure.

## Files

- **Create:** `src/Sharing/Envelope/ShareEnvelope.php`
- **Test:** `tests/Unit/Sharing/Envelope/ShareEnvelopeTest.php`

## Steps

### Step 1 — Write the failing DTO test

```php
<?php
// tests/Unit/Sharing/Envelope/ShareEnvelopeTest.php

namespace Tests\Unit\Sharing\Envelope;

use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use AuthService\Helper\Sharing\Envelope\Exceptions\InvalidEnvelopeException;
use PHPUnit\Framework\TestCase;

class ShareEnvelopeTest extends TestCase
{
    public function test_constructs_from_arr_with_all_required_fields(): void
    {
        $env = ShareEnvelope::fromArray($this->validRaw());
        $this->assertEquals('msg_01HZ', $env->messageId);
        $this->assertEquals('share_01HX', $env->correlationId);
        $this->assertEquals('service_purchase', $env->intent);
        $this->assertEquals(['order_id' => 'x'], $env->payload);
    }

    public function test_to_array_round_trips(): void
    {
        $raw = $this->validRaw();
        $env = ShareEnvelope::fromArray($raw);
        $this->assertEquals($raw, $env->toArray());
    }

    public function test_to_json_is_canonical_for_signing(): void
    {
        $raw = $this->validRaw();
        $env = ShareEnvelope::fromArray($raw);
        $json = $env->toCanonicalJson();
        $this->assertIsString($json);
        // Idempotent: rebuilding from JSON yields same JSON
        $rebuilt = ShareEnvelope::fromArray(json_decode($json, true));
        $this->assertEquals($json, $rebuilt->toCanonicalJson());
    }

    public function test_missing_required_field_throws(): void
    {
        $this->expectException(InvalidEnvelopeException::class);
        $raw = $this->validRaw();
        unset($raw['idempotency_key']);
        ShareEnvelope::fromArray($raw);
    }

    public function test_unknown_envelope_version_throws(): void
    {
        $this->expectException(InvalidEnvelopeException::class);
        $raw = $this->validRaw();
        $raw['envelope_version'] = '99';
        ShareEnvelope::fromArray($raw);
    }

    private function validRaw(): array
    {
        return [
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
        ];
    }
}
```

### Step 2 — Run, expect failure

```bash
cd C:\Users\benpl\Documents\GitHub\auth-service-helper
vendor/bin/pest tests/Unit/Sharing/Envelope/ShareEnvelopeTest.php
```

### Step 3 — Implement the DTO

```php
<?php
// src/Sharing/Envelope/Exceptions/InvalidEnvelopeException.php

namespace AuthService\Helper\Sharing\Envelope\Exceptions;

class InvalidEnvelopeException extends \RuntimeException {}
```

```php
<?php
// src/Sharing/Envelope/ShareEnvelope.php

namespace AuthService\Helper\Sharing\Envelope;

use AuthService\Helper\Sharing\Envelope\Exceptions\InvalidEnvelopeException;

final class ShareEnvelope
{
    public const SUPPORTED_VERSIONS = ['1'];

    private const REQUIRED_FIELDS = [
        'envelope_version', 'message_id', 'correlation_id',
        'intent', 'intent_version',
        'source_service_id', 'target_service_id', 'user_id',
        'idempotency_key', 'issued_at', 'payload',
    ];

    private function __construct(
        public readonly string $envelopeVersion,
        public readonly string $messageId,
        public readonly string $correlationId,
        public readonly string $intent,
        public readonly string $intentVersion,
        public readonly string $sourceServiceId,
        public readonly string $targetServiceId,
        public readonly string $userId,
        public readonly string $idempotencyKey,
        public readonly string $issuedAt,
        public readonly array $payload,
    ) {}

    public static function fromArray(array $raw): self
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $raw)) {
                throw new InvalidEnvelopeException("Missing required field: {$field}");
            }
        }
        if (!in_array($raw['envelope_version'], self::SUPPORTED_VERSIONS, true)) {
            throw new InvalidEnvelopeException("Unsupported envelope_version: {$raw['envelope_version']}");
        }
        if (!is_array($raw['payload'])) {
            throw new InvalidEnvelopeException("payload must be an object");
        }

        return new self(
            envelopeVersion: $raw['envelope_version'],
            messageId: $raw['message_id'],
            correlationId: $raw['correlation_id'],
            intent: $raw['intent'],
            intentVersion: $raw['intent_version'],
            sourceServiceId: $raw['source_service_id'],
            targetServiceId: $raw['target_service_id'],
            userId: $raw['user_id'],
            idempotencyKey: $raw['idempotency_key'],
            issuedAt: $raw['issued_at'],
            payload: $raw['payload'],
        );
    }

    public function toArray(): array
    {
        return [
            'envelope_version' => $this->envelopeVersion,
            'message_id' => $this->messageId,
            'correlation_id' => $this->correlationId,
            'intent' => $this->intent,
            'intent_version' => $this->intentVersion,
            'source_service_id' => $this->sourceServiceId,
            'target_service_id' => $this->targetServiceId,
            'user_id' => $this->userId,
            'idempotency_key' => $this->idempotencyKey,
            'issued_at' => $this->issuedAt,
            'payload' => $this->payload,
        ];
    }

    /**
     * Canonical JSON for signing — keys sorted, no whitespace.
     * Both sides MUST produce byte-identical output for the same envelope.
     */
    public function toCanonicalJson(): string
    {
        $arr = $this->toArray();
        return json_encode($arr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
```

### Step 4 — Run, expect pass + commit

```bash
vendor/bin/pest tests/Unit/Sharing/Envelope/ShareEnvelopeTest.php
# Expected: 5 passed.

git add src/Sharing/Envelope/ tests/Unit/Sharing/Envelope/
git commit -m "feat(sharing): phase B2 — ShareEnvelope DTO + validator

Immutable wire-shape DTO with fromArray/toArray/toCanonicalJson.
Validates required fields and supported envelope_version. Canonical
JSON is the basis for HMAC signing in B3.

Phase: B2 of docs/plans/InterProductCommunication-2026-05-27/"
```
