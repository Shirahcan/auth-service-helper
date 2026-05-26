# Phase A2 — `HandoffToken` model + factory

**Repo:** `auth-service`
**Spec section:** §4
**Depends on:** A1

## Goal

Eloquent model + factory for `handoff_tokens` table, plus the constants and helpers the mint/exchange controllers will use.

## Files

- **Create:** `project/app/Models/HandoffToken.php`
- **Create:** `project/database/factories/HandoffTokenFactory.php`
- **Test:** `project/tests/Unit/Models/HandoffTokenTest.php`

## Steps

### Step 1 — Write the failing model test

```php
<?php
// project/tests/Unit/Models/HandoffTokenTest.php

namespace Tests\Unit\Models;

use App\Models\HandoffToken;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandoffTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_valid_token(): void
    {
        $token = HandoffToken::factory()->create();
        $this->assertNotEmpty($token->id);
        $this->assertEquals(64, strlen($token->token_hash));
        $this->assertFalse($token->isConsumed());
        $this->assertFalse($token->isExpired());
    }

    public function test_default_ttl_is_60_seconds(): void
    {
        Carbon::setTestNow('2026-05-27 10:00:00');
        $token = HandoffToken::factory()->create();
        $this->assertEquals(60, $token->created_at->diffInSeconds($token->expires_at));
        Carbon::setTestNow();
    }

    public function test_is_expired_when_past_expiry(): void
    {
        $token = HandoffToken::factory()->create(['expires_at' => now()->subSecond()]);
        $this->assertTrue($token->isExpired());
    }

    public function test_is_consumed_when_consumed_at_set(): void
    {
        $token = HandoffToken::factory()->create(['consumed_at' => now()]);
        $this->assertTrue($token->isConsumed());
    }

    public function test_hash_token_produces_64_char_sha256(): void
    {
        $hash = HandoffToken::hashToken('opaque-random-string');
        $this->assertEquals(64, strlen($hash));
        $this->assertEquals(hash('sha256', 'opaque-random-string'), $hash);
    }
}
```

### Step 2 — Run, expect failure

```bash
cd C:\Users\benpl\Documents\GitHub\auth-service\project
php artisan test --filter=HandoffTokenTest
```

Expected: `Class "App\Models\HandoffToken" not found`.

### Step 3 — Write the model + factory

```php
<?php
// project/app/Models/HandoffToken.php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HandoffToken extends Model
{
    use HasFactory, HasUuids;

    public const DEFAULT_TTL_SECONDS = 60;

    public $timestamps = false;

    protected $fillable = [
        'token_hash', 'share_id', 'target_service_id', 'next_path',
        'minted_by_service_id', 'expires_at', 'consumed_at',
        'consumed_by_service_id', 'replay_attempts', 'created_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'created_at' => 'datetime',
        'replay_attempts' => 'integer',
    ];

    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }
}
```

```php
<?php
// project/database/factories/HandoffTokenFactory.php

namespace Database\Factories;

use App\Models\HandoffToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class HandoffTokenFactory extends Factory
{
    protected $model = HandoffToken::class;

    public function definition(): array
    {
        $now = now();
        return [
            'id' => Str::uuid()->toString(),
            'token_hash' => HandoffToken::hashToken(Str::random(40)),
            'share_id' => Str::uuid()->toString(),
            'target_service_id' => Str::uuid()->toString(),
            'next_path' => '/cases/'.Str::uuid(),
            'minted_by_service_id' => Str::uuid()->toString(),
            'expires_at' => $now->copy()->addSeconds(HandoffToken::DEFAULT_TTL_SECONDS),
            'consumed_at' => null,
            'consumed_by_service_id' => null,
            'replay_attempts' => 0,
            'created_at' => $now,
        ];
    }

    public function consumed(): self
    {
        return $this->state(['consumed_at' => now(), 'consumed_by_service_id' => $this->faker->uuid]);
    }

    public function expired(): self
    {
        return $this->state(['expires_at' => now()->subSecond()]);
    }
}
```

### Step 4 — Run, expect pass

```bash
php artisan test --filter=HandoffTokenTest
```

Expected: 5 passed.

### Step 5 — Commit

```bash
git add project/app/Models/HandoffToken.php \
        project/database/factories/HandoffTokenFactory.php \
        project/tests/Unit/Models/HandoffTokenTest.php
git commit -m "feat(sharing): phase A2 — HandoffToken model + factory

Eloquent model with consumed/expired predicates, SHA-256 hashing helper,
60s default TTL. Factory provides consumed() and expired() states for
controller tests in A3/A4.

Phase: A2 of docs/plans/InterProductCommunication-2026-05-27/"
```
