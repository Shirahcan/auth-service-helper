# Phase B1 — PrepResource model + migration + factory

**Repo:** `auth-service-helper`
**Spec section:** Data model (`prep_resources` table)
**Depends on:** v1.3 (B1 + Database\Factories autoload)

## Goal

Persistence primitive for the temp store. Eloquent model with state constants + predicates; migration with the UNIQUE constraint that makes idempotent prepare cheap; factory for downstream tests.

## Files

- **Create:** `database/migrations/2026_05_27_150000_create_prep_resources_table.php`
- **Create:** `src/Sharing/Prep/PrepResource.php`
- **Create:** `database/factories/PrepResourceFactory.php`
- **Test:** `tests/Unit/Sharing/Prep/PrepResourceTest.php`

## Steps

### Step 1 — Failing test

```php
<?php
// tests/Unit/Sharing/Prep/PrepResourceTest.php
namespace Tests\Unit\Sharing\Prep;

use AuthService\Helper\Sharing\Prep\PrepResource;
use Database\Factories\PrepResourceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

class PrepResourceTest extends TestCase
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

    public function test_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('prep_resources'));
        foreach ([
            'id', 'source_service_id', 'idempotency_key', 'intent', 'intent_version',
            'source_resource', 'student_data', 'payload', 'signed_data', 'return_to',
            'status', 'prepared_at', 'signed_at', 'promoted_at', 'expires_at',
            'permanent_resource_id', 'created_at', 'updated_at',
        ] as $col) {
            $this->assertTrue(Schema::hasColumn('prep_resources', $col), "missing column: {$col}");
        }
    }

    public function test_unique_idempotency_index_exists(): void
    {
        $indexes = collect(Schema::getIndexes('prep_resources'));
        $this->assertTrue(
            $indexes->contains(fn ($i) =>
                $i['unique']
                && in_array('source_service_id', $i['columns'], true)
                && in_array('idempotency_key', $i['columns'], true)
            ),
        );
    }

    public function test_factory_defaults_to_prepared_status(): void
    {
        $row = PrepResourceFactory::new()->create();
        $this->assertEquals(PrepResource::STATUS_PREPARED, $row->status);
        $this->assertNotNull($row->prepared_at);
        $this->assertNull($row->signed_at);
        $this->assertNull($row->promoted_at);
        $this->assertNotNull($row->expires_at);
    }

    public function test_state_predicates(): void
    {
        $prepared = PrepResourceFactory::new()->create();
        $signed   = PrepResourceFactory::new()->create(['status' => PrepResource::STATUS_SIGNED]);
        $promoted = PrepResourceFactory::new()->create(['status' => PrepResource::STATUS_PROMOTED]);

        $this->assertTrue($prepared->isPrepared());
        $this->assertFalse($prepared->isSigned());

        $this->assertTrue($signed->isSigned());
        $this->assertFalse($signed->isPromoted());

        $this->assertTrue($promoted->isPromoted());
        $this->assertTrue($promoted->isTerminal());
        $this->assertFalse($prepared->isTerminal());
    }
}
```

### Step 2 — Run, expect failure

```bash
cd C:\Users\benpl\Documents\GitHub\auth-service-helper
vendor/bin/phpunit tests/Unit/Sharing/Prep/PrepResourceTest.php
```

### Step 3 — Migration

```php
<?php
// database/migrations/2026_05_27_150000_create_prep_resources_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('prep_resources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('source_service_id');
            $table->string('idempotency_key', 255);
            $table->string('intent', 128);
            $table->string('intent_version', 16)->default('1.0');
            $table->json('source_resource');
            $table->json('student_data');
            $table->json('payload')->nullable();
            $table->json('signed_data')->nullable();
            $table->string('return_to', 2048)->nullable();
            $table->string('status', 32)->default('prepared');
            $table->timestamp('prepared_at');
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamp('expires_at');
            $table->uuid('permanent_resource_id')->nullable();
            $table->timestamps();

            $table->unique(['source_service_id', 'idempotency_key'], 'prep_resources_src_idemp_unique');
            $table->index(['status', 'expires_at']);
            $table->index(['intent', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prep_resources');
    }
};
```

### Step 4 — Model + factory

```php
<?php
// src/Sharing/Prep/PrepResource.php
namespace AuthService\Helper\Sharing\Prep;

use Database\Factories\PrepResourceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PrepResource extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PREPARED = 'prepared';
    public const STATUS_SIGNED   = 'signed';
    public const STATUS_PROMOTED = 'promoted';
    public const STATUS_EXPIRED  = 'expired';

    public const TERMINAL_STATES = [self::STATUS_PROMOTED];
    public const GC_ELIGIBLE_STATES = [self::STATUS_PREPARED, self::STATUS_SIGNED, self::STATUS_EXPIRED];

    protected $table = 'prep_resources';

    protected $fillable = [
        'id', 'source_service_id', 'idempotency_key', 'intent', 'intent_version',
        'source_resource', 'student_data', 'payload', 'signed_data', 'return_to',
        'status', 'prepared_at', 'signed_at', 'promoted_at', 'expires_at',
        'permanent_resource_id',
    ];

    protected $casts = [
        'source_resource' => 'array',
        'student_data'    => 'array',
        'payload'         => 'array',
        'signed_data'     => 'array',
        'prepared_at'     => 'datetime',
        'signed_at'       => 'datetime',
        'promoted_at'     => 'datetime',
        'expires_at'      => 'datetime',
    ];

    public function isPrepared(): bool { return $this->status === self::STATUS_PREPARED; }
    public function isSigned(): bool   { return $this->status === self::STATUS_SIGNED; }
    public function isPromoted(): bool { return $this->status === self::STATUS_PROMOTED; }
    public function isExpired(): bool  { return $this->status === self::STATUS_EXPIRED; }
    public function isTerminal(): bool { return in_array($this->status, self::TERMINAL_STATES, true); }

    public function hasExpired(?\DateTimeInterface $now = null): bool
    {
        $now = $now ?? now();
        return $this->expires_at && $this->expires_at->lt($now);
    }

    protected static function newFactory(): PrepResourceFactory
    {
        return PrepResourceFactory::new();
    }
}
```

```php
<?php
// database/factories/PrepResourceFactory.php
namespace Database\Factories;

use AuthService\Helper\Sharing\Prep\PrepResource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PrepResourceFactory extends Factory
{
    protected $model = PrepResource::class;

    public function definition(): array
    {
        return [
            'id'                 => (string) Str::uuid(),
            'source_service_id'  => (string) Str::uuid(),
            'idempotency_key'    => 'studendly:checkout:' . Str::random(10) . ':agreement:visa-rep',
            'intent'             => 'agreement_sign',
            'intent_version'     => '1.0',
            'source_resource'    => ['type' => 'checkout', 'id' => 'ckt_' . Str::random(6)],
            'student_data'       => ['external_id' => (string) Str::uuid(), 'email' => 'jane@example.test', 'name' => 'Jane Student'],
            'payload'            => ['agreement_slug' => 'visa-rep-agreement'],
            'signed_data'        => null,
            'return_to'          => 'https://studendly.test/checkout/done',
            'status'             => PrepResource::STATUS_PREPARED,
            'prepared_at'        => now(),
            'signed_at'          => null,
            'promoted_at'        => null,
            'expires_at'         => now()->addHours(24),
            'permanent_resource_id' => null,
        ];
    }
}
```

### Step 5 — Run + commit

```bash
vendor/bin/phpunit tests/Unit/Sharing/Prep/PrepResourceTest.php
# Expected: 4 passed.

git add database/migrations/2026_05_27_150000_create_prep_resources_table.php \
        src/Sharing/Prep/PrepResource.php \
        database/factories/PrepResourceFactory.php \
        tests/Unit/Sharing/Prep/PrepResourceTest.php
git commit -m "feat(sharing): phase B1 — PrepResource model + migration

The temp-store primitive. UNIQUE(source_service_id, idempotency_key) is
what makes idempotent /prepare cheap. State constants + predicates are
exposed for the repository (B2) and the embed/promote controllers (C).

Phase: B1 of docs/plans/PrepSignPromote-2026-05-27/"
```

## Verification checklist

- [ ] UNIQUE(source_service_id, idempotency_key) index verified by test
- [ ] Factory defaults: status=prepared, expires_at=now+24h, no signed/promoted timestamps
- [ ] `isTerminal()` returns true ONLY for `promoted`
- [ ] `hasExpired()` works against an injected `now` for deterministic GC tests
