# Phase B6 — `IntentRegistry` + `SharePayload` contract

**Repo:** `auth-service-helper`
**Spec section:** §6 "Intent Registry"
**Depends on:** B1

## Goal

The pluggable registry where built-in (Phase B7) and product-defined intents are bound to typed payload classes + JSON-schema validators.

## Files

- **Create:** `src/Sharing/Intents/Contracts/SharePayload.php`
- **Create:** `src/Sharing/Intents/IntentRegistry.php`
- **Create:** `src/Sharing/Intents/Exceptions/UnknownIntentException.php`
- **Test:** `tests/Unit/Sharing/Intents/IntentRegistryTest.php`

## Steps

### Step 1 — Failing test

```php
<?php
// tests/Unit/Sharing/Intents/IntentRegistryTest.php

namespace Tests\Unit\Sharing\Intents;

use AuthService\Helper\Sharing\Intents\Contracts\SharePayload;
use AuthService\Helper\Sharing\Intents\Exceptions\UnknownIntentException;
use AuthService\Helper\Sharing\Intents\IntentRegistry;
use PHPUnit\Framework\TestCase;

class IntentRegistryTest extends TestCase
{
    public function test_register_and_resolve_intent_class(): void
    {
        $reg = new IntentRegistry();
        $reg->register('test.intent', FakePayload::class);

        $this->assertEquals(FakePayload::class, $reg->payloadClassFor('test.intent'));
    }

    public function test_register_and_get_schema_path(): void
    {
        $reg = new IntentRegistry();
        $reg->register('test.intent', FakePayload::class, schemaPath: '/path/to/schema.json');

        $this->assertEquals('/path/to/schema.json', $reg->schemaPathFor('test.intent'));
    }

    public function test_unknown_intent_throws(): void
    {
        $reg = new IntentRegistry();
        $this->expectException(UnknownIntentException::class);
        $reg->payloadClassFor('does.not.exist');
    }

    public function test_known_intents_returns_all_registered(): void
    {
        $reg = new IntentRegistry();
        $reg->register('a', FakePayload::class);
        $reg->register('b', FakePayload::class);
        $this->assertEquals(['a', 'b'], $reg->knownIntents());
    }

    public function test_hydrate_payload_constructs_via_from_array(): void
    {
        $reg = new IntentRegistry();
        $reg->register('test.intent', FakePayload::class);

        $payload = $reg->hydrate('test.intent', ['order_id' => 'xyz']);
        $this->assertInstanceOf(FakePayload::class, $payload);
        $this->assertEquals('xyz', $payload->orderId);
    }
}

class FakePayload implements SharePayload
{
    public function __construct(public readonly string $orderId) {}
    public static function fromArray(array $raw): self { return new self($raw['order_id']); }
    public function toArray(): array { return ['order_id' => $this->orderId]; }
    public static function intentSlug(): string { return 'test.intent'; }
    public static function intentVersion(): string { return '1.0'; }
}
```

### Step 2 — Run, expect failure

```bash
vendor/bin/pest tests/Unit/Sharing/Intents/IntentRegistryTest.php
```

### Step 3 — Implement contract + registry

```php
<?php
// src/Sharing/Intents/Contracts/SharePayload.php

namespace AuthService\Helper\Sharing\Intents\Contracts;

interface SharePayload
{
    public static function fromArray(array $raw): self;
    public function toArray(): array;
    public static function intentSlug(): string;
    public static function intentVersion(): string;
}
```

```php
<?php
// src/Sharing/Intents/Exceptions/UnknownIntentException.php
namespace AuthService\Helper\Sharing\Intents\Exceptions;
class UnknownIntentException extends \RuntimeException {}
```

```php
<?php
// src/Sharing/Intents/IntentRegistry.php

namespace AuthService\Helper\Sharing\Intents;

use AuthService\Helper\Sharing\Intents\Contracts\SharePayload;
use AuthService\Helper\Sharing\Intents\Exceptions\UnknownIntentException;

class IntentRegistry
{
    /** @var array<string, array{class: class-string<SharePayload>, schema: ?string}> */
    private array $intents = [];

    public function register(string $slug, string $payloadClass, ?string $schemaPath = null): void
    {
        if (!is_subclass_of($payloadClass, SharePayload::class)) {
            throw new \InvalidArgumentException(
                "Payload class {$payloadClass} must implement SharePayload"
            );
        }
        $this->intents[$slug] = ['class' => $payloadClass, 'schema' => $schemaPath];
    }

    public function payloadClassFor(string $slug): string
    {
        if (!isset($this->intents[$slug])) {
            throw new UnknownIntentException("Unknown intent: {$slug}");
        }
        return $this->intents[$slug]['class'];
    }

    public function schemaPathFor(string $slug): ?string
    {
        return $this->intents[$slug]['schema'] ?? null;
    }

    public function hydrate(string $slug, array $rawPayload): SharePayload
    {
        $class = $this->payloadClassFor($slug);
        return $class::fromArray($rawPayload);
    }

    public function knownIntents(): array
    {
        return array_keys($this->intents);
    }
}
```

### Step 4 — Bind as singleton in SharingServiceProvider

Edit `src/Sharing/SharingServiceProvider.php`:

```php
public function register(): void
{
    $this->app->singleton(\AuthService\Helper\Sharing\Intents\IntentRegistry::class);
}
```

### Step 5 — Run + commit

```bash
vendor/bin/pest tests/Unit/Sharing/Intents/IntentRegistryTest.php
# Expected: 5 passed.

git add src/Sharing/ tests/Unit/Sharing/Intents/
git commit -m "feat(sharing): phase B6 — IntentRegistry + SharePayload contract

Pluggable registry binding intent slugs to payload classes and
optional JSON-schema paths. Bound as singleton from
SharingServiceProvider. Built-in intents registered in B7.

Phase: B6 of docs/plans/InterProductCommunication-2026-05-27/"
```
