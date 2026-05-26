# Phase A1 — `handoff_tokens` migration

**Repo:** `auth-service`
**Spec section:** §4 "Auth-Service Additions"
**Depends on:** Nothing (first phase of Phase A)

## Goal

Create the `handoff_tokens` table that backs the single-use, ~60s TTL handoff-token primitive used for seamless cross-product redirect login.

## Files

- **Create:** `project/database/migrations/2026_05_27_120000_create_handoff_tokens_table.php`
- **Test:** `project/tests/Feature/Migrations/HandoffTokensMigrationTest.php`

## Steps

### Step 1 — Write the failing migration test

```php
<?php
// project/tests/Feature/Migrations/HandoffTokensMigrationTest.php

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HandoffTokensMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_handoff_tokens_table_exists_with_required_columns(): void
    {
        $this->assertTrue(Schema::hasTable('handoff_tokens'));

        foreach ([
            'id', 'token_hash', 'share_id', 'target_service_id',
            'next_path', 'minted_by_service_id',
            'expires_at', 'consumed_at', 'consumed_by_service_id',
            'replay_attempts', 'created_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('handoff_tokens', $col),
                "handoff_tokens missing column: {$col}"
            );
        }
    }

    public function test_token_hash_is_unique(): void
    {
        $indexes = collect(Schema::getIndexes('handoff_tokens'));
        $this->assertTrue(
            $indexes->contains(fn ($i) => in_array('token_hash', $i['columns']) && $i['unique']),
            'token_hash must be UNIQUE'
        );
    }
}
```

### Step 2 — Run, expect failure

```bash
cd C:\Users\benpl\Documents\GitHub\auth-service\project
php artisan test --filter=HandoffTokensMigrationTest
```

Expected: `Schema::hasTable('handoff_tokens')` returns false → test fails.

### Step 3 — Write the migration

```php
<?php
// project/database/migrations/2026_05_27_120000_create_handoff_tokens_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('handoff_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('token_hash', 64)->unique();
            $table->uuid('share_id');
            $table->uuid('target_service_id');
            $table->string('next_path', 2048)->nullable();
            $table->uuid('minted_by_service_id');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->uuid('consumed_by_service_id')->nullable();
            $table->unsignedInteger('replay_attempts')->default(0);
            $table->timestamp('created_at');

            $table->index('share_id');
            $table->index(['target_service_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('handoff_tokens');
    }
};
```

Notes:
- **No FK to `user_shares.id`.** Reason: handoff tokens may outlive a share by seconds in race conditions, and tokens are short-lived anyway. Soft reference is fine; cascade-on-revoke is handled at the application layer in Phase A4.
- `replay_attempts` is for observability + alerting only; not used for rejection (rejection is atomic via `consumed_at`).

### Step 4 — Run, expect pass

```bash
php artisan test --filter=HandoffTokensMigrationTest
```

Expected: 2 passed.

### Step 5 — Commit

```bash
git add project/database/migrations/2026_05_27_120000_create_handoff_tokens_table.php \
        project/tests/Feature/Migrations/HandoffTokensMigrationTest.php
git commit -m "feat(sharing): phase A1 — handoff_tokens migration

Single-use, ~60s TTL token rows for cross-product seamless-redirect
login. Atomic consumption enforced at application layer in A4.

Phase: A1 of docs/plans/InterProductCommunication-2026-05-27/
Spec: docs/superpowers/specs/2026-05-26-inter-product-communication-design.md"
```

## Verification checklist

- [ ] Migration up + down both clean (run `php artisan migrate && php artisan migrate:rollback && php artisan migrate`)
- [ ] No FK constraints added (intentional, see notes above)
- [ ] Indexes match spec §4 schema exactly
