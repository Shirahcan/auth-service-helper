# Phase D1 — `InboundShareMessage` model + migration

**Repo:** `auth-service-helper`
**Spec section:** §9 "Destination Side (`inbound_share_messages`)" + §10 "Idempotency Model"
**Depends on:** B1 (SharingServiceProvider scaffold)

## Goal

Create the `inbound_share_messages` table and Eloquent model that backs the destination-side inbox: every envelope that hits the helper's webhook is persisted here (including duplicates and rejects), and the `UNIQUE(source_service_id, idempotency_key)` constraint is what makes the idempotency guard cheap and correct.

## Files

- **Create:** `database/migrations/2026_05_27_130000_create_inbound_share_messages_table.php`
- **Create:** `src/Sharing/Inbox/InboundShareMessage.php`
- **Create:** `database/factories/InboundShareMessageFactory.php`
- **Test:** `tests/Unit/Sharing/Inbox/InboundShareMessageTest.php`

## Steps

### Step 1 — Write the failing model/migration test

```php
<?php
// tests/Unit/Sharing/Inbox/InboundShareMessageTest.php

namespace Tests\Unit\Sharing\Inbox;

use AuthService\Helper\Sharing\Inbox\InboundShareMessage;
use Database\Factories\InboundShareMessageFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

class InboundShareMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [\AuthService\Helper\AuthServiceHelperServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../../database/migrations');
    }

    public function test_table_exists_with_required_columns(): void
    {
        $this->assertTrue(Schema::hasTable('inbound_share_messages'));
        foreach ([
            'id', 'envelope_version', 'message_id', 'correlation_id',
            'intent', 'intent_version',
            'source_service_id', 'target_service_id', 'user_id',
            'idempotency_key', 'issued_at', 'payload',
            'signature_header', 'headers',
            'received_at', 'processing_status', 'processing_error',
            'dispatched_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('inbound_share_messages', $col),
                "inbound_share_messages missing column: {$col}"
            );
        }
    }

    public function test_unique_source_idempotency_key_index_exists(): void
    {
        $indexes = collect(Schema::getIndexes('inbound_share_messages'));
        $this->assertTrue(
            $indexes->contains(fn ($i) =>
                $i['unique'] === true
                && in_array('source_service_id', $i['columns'], true)
                && in_array('idempotency_key', $i['columns'], true)
            ),
            'Expected UNIQUE(source_service_id, idempotency_key) index'
        );
    }

    public function test_factory_creates_row_with_received_status(): void
    {
        $row = InboundShareMessageFactory::new()->create();
        $this->assertInstanceOf(InboundShareMessage::class, $row);
        $this->assertEquals('received', $row->processing_status);
        $this->assertIsArray($row->payload);
        $this->assertIsArray($row->headers);
    }

    public function test_helper_methods_isDispatched_and_isDuplicate(): void
    {
        $dispatched = InboundShareMessageFactory::new()->create([
            'processing_status' => 'dispatched',
        ]);
        $dup = InboundShareMessageFactory::new()->create([
            'processing_status' => 'duplicate',
        ]);

        $this->assertTrue($dispatched->isDispatched());
        $this->assertFalse($dispatched->isDuplicate());
        $this->assertTrue($dup->isDuplicate());
        $this->assertFalse($dup->isDispatched());
    }
}
```

### Step 2 — Run, expect failure

```bash
cd C:\Users\benpl\Documents\GitHub\auth-service-helper
vendor/bin/pest tests/Unit/Sharing/Inbox/InboundShareMessageTest.php
```

Expected: `Schema::hasTable('inbound_share_messages')` returns false (and class-not-found for model/factory).

### Step 3 — Write the migration

```php
<?php
// database/migrations/2026_05_27_130000_create_inbound_share_messages_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inbound_share_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('envelope_version', 16);
            $table->string('message_id', 64);
            $table->uuid('correlation_id'); // share_id
            $table->string('intent', 128);
            $table->string('intent_version', 16);
            $table->uuid('source_service_id');
            $table->uuid('target_service_id');
            $table->uuid('user_id');
            $table->string('idempotency_key', 191);
            $table->timestamp('issued_at');
            $table->json('payload');
            $table->string('signature_header', 191);
            $table->json('headers')->nullable();
            $table->timestamp('received_at');
            $table->enum('processing_status', [
                'received', 'validated', 'dispatched',
                'rejected_signature', 'rejected_schema', 'duplicate',
            ])->default('received');
            $table->text('processing_error')->nullable();
            $table->timestamp('dispatched_at')->nullable();

            $table->unique(['source_service_id', 'idempotency_key'], 'inbound_share_msgs_src_idemp_unique');
            $table->index('correlation_id');
            $table->index('processing_status');
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_share_messages');
    }
};
```

### Step 4 — Write the model + factory

```php
<?php
// src/Sharing/Inbox/InboundShareMessage.php

namespace AuthService\Helper\Sharing\Inbox;

use Database\Factories\InboundShareMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InboundShareMessage extends Model
{
    use HasFactory;
    use HasUuids;

    public $timestamps = false;

    protected $table = 'inbound_share_messages';

    protected $fillable = [
        'id',
        'envelope_version', 'message_id', 'correlation_id',
        'intent', 'intent_version',
        'source_service_id', 'target_service_id', 'user_id',
        'idempotency_key', 'issued_at', 'payload',
        'signature_header', 'headers',
        'received_at', 'processing_status', 'processing_error',
        'dispatched_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'issued_at' => 'datetime',
        'received_at' => 'datetime',
        'dispatched_at' => 'datetime',
    ];

    public function isDispatched(): bool
    {
        return $this->processing_status === 'dispatched';
    }

    public function isDuplicate(): bool
    {
        return $this->processing_status === 'duplicate';
    }

    protected static function newFactory(): InboundShareMessageFactory
    {
        return InboundShareMessageFactory::new();
    }
}
```

```php
<?php
// database/factories/InboundShareMessageFactory.php

namespace Database\Factories;

use AuthService\Helper\Sharing\Inbox\InboundShareMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class InboundShareMessageFactory extends Factory
{
    protected $model = InboundShareMessage::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'envelope_version' => '1',
            'message_id' => 'msg_' . Str::random(20),
            'correlation_id' => (string) Str::uuid(),
            'intent' => 'service_purchase',
            'intent_version' => '1.0',
            'source_service_id' => (string) Str::uuid(),
            'target_service_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'idempotency_key' => 'studendly:order:' . $this->faker->randomNumber(6),
            'issued_at' => now(),
            'payload' => ['order_id' => 'ord_' . Str::random(10)],
            'signature_header' => 't=' . time() . ',v1=' . str_repeat('a', 64),
            'headers' => ['X-Trust-Key' => 'trust_xxx'],
            'received_at' => now(),
            'processing_status' => 'received',
            'processing_error' => null,
            'dispatched_at' => null,
        ];
    }
}
```

### Step 5 — Run + commit

```bash
vendor/bin/pest tests/Unit/Sharing/Inbox/InboundShareMessageTest.php
# Expected: 4 passed.

git add database/migrations/2026_05_27_130000_create_inbound_share_messages_table.php \
        src/Sharing/Inbox/InboundShareMessage.php \
        database/factories/InboundShareMessageFactory.php \
        tests/Unit/Sharing/Inbox/InboundShareMessageTest.php
git commit -m "feat(sharing): phase D1 — InboundShareMessage model + migration

Destination-side inbox row. UNIQUE(source_service_id, idempotency_key)
is what makes the IdempotencyGuard cheap and atomic. Enum models the
full §9 lifecycle (received/validated/dispatched/rejected_*/duplicate).
isDispatched/isDuplicate helpers used by the webhook controller in D2.

Phase: D1 of docs/plans/InterProductCommunication-2026-05-27/"
```

## Verification checklist

- [ ] Migration runs clean up + rollback + up
- [ ] UNIQUE index on `(source_service_id, idempotency_key)` is present (verified by Schema::getIndexes)
- [ ] Factory produces `processing_status='received'` by default
- [ ] Model casts `payload` + `headers` to arrays automatically
