# Phase B7a — Built-in payloads I: `ServicePurchase`, `ProfileSync`

**Repo:** `auth-service-helper`
**Spec section:** §6 starter catalogue
**Depends on:** B6

## Goal

First two of the seven built-in intents — the two most central to the driving Studendly→Portify use case.

## Files

- **Create:** `src/Sharing/Intents/Builtin/ServicePurchasePayload.php`
- **Create:** `src/Sharing/Intents/Builtin/ProfileSyncPayload.php`
- **Create:** `src/Sharing/Intents/Builtin/schemas/service-purchase.json`
- **Create:** `src/Sharing/Intents/Builtin/schemas/profile-sync.json`
- **Test:** `tests/Unit/Sharing/Intents/Builtin/ServicePurchasePayloadTest.php`
- **Test:** `tests/Unit/Sharing/Intents/Builtin/ProfileSyncPayloadTest.php`

## Steps

### Step 1 — Failing tests

```php
<?php
// tests/Unit/Sharing/Intents/Builtin/ServicePurchasePayloadTest.php

namespace Tests\Unit\Sharing\Intents\Builtin;

use AuthService\Helper\Sharing\Intents\Builtin\ServicePurchasePayload;
use PHPUnit\Framework\TestCase;

class ServicePurchasePayloadTest extends TestCase
{
    public function test_round_trips(): void
    {
        $raw = [
            'order_id' => 'studendly:order:88421',
            'service_slug' => 'study_visa',
            'amount_cents' => 40000,
            'currency' => 'CAD',
            'purchased_at' => '2026-05-27T10:00:00Z',
            'items' => [['sku' => 'svc.consult', 'qty' => 1, 'amount_cents' => 40000]],
        ];
        $payload = ServicePurchasePayload::fromArray($raw);
        $this->assertEquals(40000, $payload->amountCents);
        $this->assertEquals($raw, $payload->toArray());
        $this->assertEquals('service_purchase', ServicePurchasePayload::intentSlug());
    }
}
```

```php
<?php
// tests/Unit/Sharing/Intents/Builtin/ProfileSyncPayloadTest.php

namespace Tests\Unit\Sharing\Intents\Builtin;

use AuthService\Helper\Sharing\Intents\Builtin\ProfileSyncPayload;
use PHPUnit\Framework\TestCase;

class ProfileSyncPayloadTest extends TestCase
{
    public function test_round_trips(): void
    {
        $raw = [
            'fields_changed' => ['phone', 'address'],
            'snapshot' => ['name' => 'Alice', 'phone' => '+1...'],
        ];
        $payload = ProfileSyncPayload::fromArray($raw);
        $this->assertEquals(['phone', 'address'], $payload->fieldsChanged);
        $this->assertEquals($raw, $payload->toArray());
    }
}
```

### Step 2 — Run, expect failure

```bash
vendor/bin/pest tests/Unit/Sharing/Intents/Builtin/
```

### Step 3 — Implement payloads + schemas

```php
<?php
// src/Sharing/Intents/Builtin/ServicePurchasePayload.php

namespace AuthService\Helper\Sharing\Intents\Builtin;

use AuthService\Helper\Sharing\Intents\Contracts\SharePayload;

final class ServicePurchasePayload implements SharePayload
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $serviceSlug,
        public readonly int $amountCents,
        public readonly string $currency,
        public readonly string $purchasedAt,
        public readonly array $items,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            orderId: $raw['order_id'],
            serviceSlug: $raw['service_slug'],
            amountCents: (int) $raw['amount_cents'],
            currency: $raw['currency'],
            purchasedAt: $raw['purchased_at'],
            items: $raw['items'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'service_slug' => $this->serviceSlug,
            'amount_cents' => $this->amountCents,
            'currency' => $this->currency,
            'purchased_at' => $this->purchasedAt,
            'items' => $this->items,
        ];
    }

    public static function intentSlug(): string { return 'service_purchase'; }
    public static function intentVersion(): string { return '1.0'; }
}
```

```php
<?php
// src/Sharing/Intents/Builtin/ProfileSyncPayload.php

namespace AuthService\Helper\Sharing\Intents\Builtin;

use AuthService\Helper\Sharing\Intents\Contracts\SharePayload;

final class ProfileSyncPayload implements SharePayload
{
    public function __construct(
        public readonly array $fieldsChanged,
        public readonly array $snapshot,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            fieldsChanged: $raw['fields_changed'] ?? [],
            snapshot: $raw['snapshot'] ?? [],
        );
    }

    public function toArray(): array
    {
        return ['fields_changed' => $this->fieldsChanged, 'snapshot' => $this->snapshot];
    }

    public static function intentSlug(): string { return 'profile_sync'; }
    public static function intentVersion(): string { return '1.0'; }
}
```

```json
// src/Sharing/Intents/Builtin/schemas/service-purchase.json
{
  "$schema": "https://json-schema.org/draft-07/schema",
  "type": "object",
  "required": ["order_id", "service_slug", "amount_cents", "currency", "purchased_at"],
  "properties": {
    "order_id":     { "type": "string", "minLength": 1 },
    "service_slug": { "type": "string", "minLength": 1 },
    "amount_cents": { "type": "integer", "minimum": 0 },
    "currency":     { "type": "string", "pattern": "^[A-Z]{3}$" },
    "purchased_at": { "type": "string", "format": "date-time" },
    "items":        { "type": "array" }
  },
  "additionalProperties": true
}
```

```json
// src/Sharing/Intents/Builtin/schemas/profile-sync.json
{
  "$schema": "https://json-schema.org/draft-07/schema",
  "type": "object",
  "properties": {
    "fields_changed": { "type": "array", "items": { "type": "string" } },
    "snapshot":       { "type": "object" }
  },
  "additionalProperties": true
}
```

### Step 4 — Register from SharingServiceProvider

In `src/Sharing/SharingServiceProvider.php` `boot()`:

```php
$this->app->afterResolving(\AuthService\Helper\Sharing\Intents\IntentRegistry::class, function ($reg) {
    $reg->register('service_purchase', \AuthService\Helper\Sharing\Intents\Builtin\ServicePurchasePayload::class,
        schemaPath: __DIR__.'/Intents/Builtin/schemas/service-purchase.json');
    $reg->register('profile_sync', \AuthService\Helper\Sharing\Intents\Builtin\ProfileSyncPayload::class,
        schemaPath: __DIR__.'/Intents/Builtin/schemas/profile-sync.json');
});
```

### Step 5 — Run + commit

```bash
vendor/bin/pest tests/Unit/Sharing/Intents/Builtin/
# Expected: 2 passed.

git add src/Sharing/Intents/Builtin/ src/Sharing/SharingServiceProvider.php tests/Unit/Sharing/Intents/Builtin/
git commit -m "feat(sharing): phase B7a — ServicePurchase + ProfileSync intents

Two built-in payload classes + JSON-schemas, auto-registered from
SharingServiceProvider. ServicePurchase is the Studendly checkout
intent; ProfileSync covers ongoing profile updates over the months-
long relationship.

Phase: B7a of docs/plans/InterProductCommunication-2026-05-27/"
```
