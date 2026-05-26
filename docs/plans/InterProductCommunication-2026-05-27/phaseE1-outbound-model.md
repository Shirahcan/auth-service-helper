# Phase E1 — `OutboundShareMessage` model + migration + states

**Repo:** `auth-service-helper`
**Spec section:** §7 "Published Migrations" + §9 "Source Side (`outbound_share_messages`)"
**Depends on:** B1 (ServiceProvider scaffolding)

## Goal

The source-side outbox row + Eloquent model that backs queued, retryable, dead-letterable delivery of share payloads to peer products. This phase creates the table, the model with state constants, and the factory. The repository (E2) and dispatch job (E3+) layer on top.

## Files

- **Create:** `database/migrations/2026_05_27_140000_create_outbound_share_messages_table.php`
- **Create:** `src/Sharing/Outbox/OutboundShareMessage.php`
- **Create:** `database/factories/OutboundShareMessageFactory.php`
- **Test:** `tests/Unit/Sharing/Outbox/OutboundShareMessageTest.php`

## Steps

### Step 1 — Failing model+migration test

```php
<?php
// tests/Unit/Sharing/Outbox/OutboundShareMessageTest.php

namespace Tests\Unit\Sharing\Outbox;

use AuthService\Helper\Sharing\Outbox\OutboundShareMessage;
use AuthService\Helper\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

class OutboundShareMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_exists_with_required_columns(): void
    {
        $this->assertTrue(Schema::hasTable('outbound_share_messages'));

        foreach ([
            'id', 'share_id', 'target_service_id', 'peer_slug',
            'intent', 'intent_version', 'idempotency_key',
            'envelope_json', 'signature_header',
            'status', 'attempts', 'last_attempt_at', 'next_retry_at',
            'delivered_at', 'dead_lettered_at',
            'last_error', 'last_response_status',
            'created_at', 'updated_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('outbound_share_messages', $col),
                "outbound_share_messages missing column: {$col}",
            );
        }
    }

    public function test_unique_peer_slug_and_idempotency_key(): void
    {
        $indexes = collect(Schema::getIndexes('outbound_share_messages'));
        $this->assertTrue(
            $indexes->contains(
                fn ($i) => $i['unique']
                    && in_array('peer_slug', $i['columns'])
                    && in_array('idempotency_key', $i['columns']),
            ),
            'UNIQUE(peer_slug, idempotency_key) index missing',
        );
    }

    public function test_factory_persists_with_defaults(): void
    {
        $row = OutboundShareMessage::factory()->create();

        $this->assertNotNull($row->id);
        $this->assertEquals(OutboundShareMessage::STATUS_QUEUED, $row->status);
        $this->assertEquals(0, $row->attempts);
        $this->assertIsArray($row->envelope_json);
    }

    public function test_state_predicates(): void
    {
        $queued = OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_QUEUED]);
        $retry  = OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_RETRY_SCHEDULED]);
        $inflight = OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_IN_FLIGHT]);
        $delivered = OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_DELIVERED]);
        $dlq = OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_DEAD_LETTERED]);
        $perm = OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_FAILED_PERMANENT]);

        $this->assertTrue($queued->isDeliverable());
        $this->assertTrue($retry->isDeliverable());
        $this->assertFalse($inflight->isDeliverable());
        $this->assertFalse($delivered->isDeliverable());

        $this->assertFalse($queued->isInTerminalState());
        $this->assertTrue($delivered->isInTerminalState());
        $this->assertTrue($dlq->isInTerminalState());
        $this->assertTrue($perm->isInTerminalState());
    }
}
```

### Step 2 — Run, expect failure

```bash
cd C:\Users\benpl\Documents\GitHub\auth-service-helper
vendor/bin/pest tests/Unit/Sharing/Outbox/OutboundShareMessageTest.php
```

Expected: table missing / class-not-found.

### Step 3 — Write the migration

```php
<?php
// database/migrations/2026_05_27_140000_create_outbound_share_messages_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('outbound_share_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('share_id');
            $table->uuid('target_service_id');
            $table->string('peer_slug', 100);

            $table->string('intent', 150);
            $table->string('intent_version', 20);
            $table->string('idempotency_key', 255);

            $table->json('envelope_json');
            $table->string('signature_header', 255)->nullable();

            $table->string('status', 32)->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('dead_lettered_at')->nullable();

            $table->text('last_error')->nullable();
            $table->unsignedSmallInteger('last_response_status')->nullable();

            $table->timestamps();

            $table->index(['status', 'next_retry_at']);
            $table->index('share_id');
            $table->index(['peer_slug', 'status']);
            $table->unique(['peer_slug', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_share_messages');
    }
};
```

### Step 4 — Write the model + factory

```php
<?php
// src/Sharing/Outbox/OutboundShareMessage.php

namespace AuthService\Helper\Sharing\Outbox;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OutboundShareMessage extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_IN_FLIGHT = 'in_flight';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_RETRY_SCHEDULED = 'retry_scheduled';
    public const STATUS_DEAD_LETTERED = 'dead_lettered';
    public const STATUS_FAILED_PERMANENT = 'failed_permanent';

    public const TERMINAL_STATES = [
        self::STATUS_DELIVERED,
        self::STATUS_DEAD_LETTERED,
        self::STATUS_FAILED_PERMANENT,
    ];

    public const DELIVERABLE_STATES = [
        self::STATUS_QUEUED,
        self::STATUS_RETRY_SCHEDULED,
    ];

    protected $table = 'outbound_share_messages';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'share_id', 'target_service_id', 'peer_slug',
        'intent', 'intent_version', 'idempotency_key',
        'envelope_json', 'signature_header',
        'status', 'attempts', 'last_attempt_at', 'next_retry_at',
        'delivered_at', 'dead_lettered_at',
        'last_error', 'last_response_status',
    ];

    protected $casts = [
        'envelope_json' => 'array',
        'attempts' => 'integer',
        'last_response_status' => 'integer',
        'last_attempt_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'delivered_at' => 'datetime',
        'dead_lettered_at' => 'datetime',
    ];

    public function isDeliverable(): bool
    {
        return in_array($this->status, self::DELIVERABLE_STATES, true);
    }

    public function isInTerminalState(): bool
    {
        return in_array($this->status, self::TERMINAL_STATES, true);
    }

    protected static function newFactory()
    {
        return \Database\Factories\OutboundShareMessageFactory::new();
    }
}
```

```php
<?php
// database/factories/OutboundShareMessageFactory.php

namespace Database\Factories;

use AuthService\Helper\Sharing\Outbox\OutboundShareMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OutboundShareMessageFactory extends Factory
{
    protected $model = OutboundShareMessage::class;

    public function definition(): array
    {
        $shareId = (string) Str::uuid();
        $idempotencyKey = 'idem:'.Str::random(16);

        return [
            'id' => (string) Str::uuid(),
            'share_id' => $shareId,
            'target_service_id' => (string) Str::uuid(),
            'peer_slug' => 'portify',
            'intent' => 'service_purchase',
            'intent_version' => '1.0',
            'idempotency_key' => $idempotencyKey,
            'envelope_json' => [
                'envelope_version' => '1',
                'message_id' => 'msg_'.Str::random(12),
                'correlation_id' => $shareId,
                'intent' => 'service_purchase',
                'intent_version' => '1.0',
                'source_service_id' => (string) Str::uuid(),
                'target_service_id' => (string) Str::uuid(),
                'user_id' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'issued_at' => now()->toIso8601String(),
                'payload' => ['order_id' => 'order:'.Str::random(6)],
            ],
            'signature_header' => null,
            'status' => OutboundShareMessage::STATUS_QUEUED,
            'attempts' => 0,
            'last_attempt_at' => null,
            'next_retry_at' => null,
            'delivered_at' => null,
            'dead_lettered_at' => null,
            'last_error' => null,
            'last_response_status' => null,
        ];
    }
}
```

### Step 5 — Run + commit

```bash
vendor/bin/pest tests/Unit/Sharing/Outbox/OutboundShareMessageTest.php
# Expected: 4 passed.

git add database/migrations/2026_05_27_140000_create_outbound_share_messages_table.php \
        database/factories/OutboundShareMessageFactory.php \
        src/Sharing/Outbox/OutboundShareMessage.php \
        tests/Unit/Sharing/Outbox/OutboundShareMessageTest.php
git commit -m "feat(sharing): phase E1 — OutboundShareMessage model + migration

Source-side outbox row with 6 states (queued, in_flight, delivered,
retry_scheduled, dead_lettered, failed_permanent). UNIQUE(peer_slug,
idempotency_key) prevents double-enqueue. Factory + model with
isDeliverable() / isInTerminalState() predicates.

Phase: E1 of docs/plans/InterProductCommunication-2026-05-27/"
```

## Verification checklist

- [ ] Migration up + down both clean
- [ ] UNIQUE(peer_slug, idempotency_key) verified by test
- [ ] All 6 state constants exist on the model
- [ ] Factory defaults to STATUS_QUEUED with attempts=0
