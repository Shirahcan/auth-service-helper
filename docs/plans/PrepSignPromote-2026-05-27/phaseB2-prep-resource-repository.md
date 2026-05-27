# Phase B2 — PrepResourceRepository

**Repo:** `auth-service-helper`
**Depends on:** B1

## Goal

Single repository owning all state transitions on `prep_resources`. The controllers (C1/C2/C3) and the GC command (G1) only mutate via this repo — keeps the state machine in one place and auditable.

## Files

- **Create:** `src/Sharing/Prep/PrepResourceRepository.php`
- **Test:** `tests/Unit/Sharing/Prep/PrepResourceRepositoryTest.php`

## Steps

### Step 1 — Failing test

```php
<?php
// tests/Unit/Sharing/Prep/PrepResourceRepositoryTest.php
namespace Tests\Unit\Sharing\Prep;

use AuthService\Helper\Sharing\Prep\PrepResource;
use AuthService\Helper\Sharing\Prep\PrepResourceRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase;

class PrepResourceRepositoryTest extends TestCase
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

    public function test_upsert_creates_new_row(): void
    {
        $repo = new PrepResourceRepository();
        $sourceId = (string) Str::uuid();
        $row = $repo->upsert(
            sourceServiceId: $sourceId,
            idempotencyKey: 'studendly:checkout:c1:agreement:visa-rep',
            intent: 'agreement_sign',
            intentVersion: '1.0',
            sourceResource: ['type' => 'checkout', 'id' => 'c1'],
            studentData: ['external_id' => 'stu_1', 'email' => 'a@b.com', 'name' => 'A'],
            payload: ['agreement_slug' => 'visa-rep'],
            returnTo: 'https://x',
            ttlPreparedHours: 24,
        );

        $this->assertEquals(PrepResource::STATUS_PREPARED, $row->status);
        $this->assertEquals(1, PrepResource::count());
    }

    public function test_upsert_is_idempotent_returns_existing(): void
    {
        $repo = new PrepResourceRepository();
        $sourceId = (string) Str::uuid();
        $a = $repo->upsert(
            sourceServiceId: $sourceId, idempotencyKey: 'k', intent: 'agreement_sign', intentVersion: '1.0',
            sourceResource: [], studentData: [], payload: [], returnTo: null, ttlPreparedHours: 24,
        );
        $b = $repo->upsert(
            sourceServiceId: $sourceId, idempotencyKey: 'k', intent: 'agreement_sign', intentVersion: '1.0',
            sourceResource: [], studentData: [], payload: [], returnTo: null, ttlPreparedHours: 24,
        );
        $this->assertEquals($a->id, $b->id);
        $this->assertEquals(1, PrepResource::count());
    }

    public function test_mark_signed_extends_expiry_and_captures_data(): void
    {
        $row = PrepResource::factory()->create([
            'expires_at' => now()->addHour(),  // about to expire
        ]);
        $repo = new PrepResourceRepository();

        $repo->markSigned($row, signedData: ['signature' => 'Jane Doe', 'ip' => '1.2.3.4'], ttlSignedHours: 72);
        $row->refresh();

        $this->assertEquals(PrepResource::STATUS_SIGNED, $row->status);
        $this->assertNotNull($row->signed_at);
        $this->assertEquals(['signature' => 'Jane Doe', 'ip' => '1.2.3.4'], $row->signed_data);
        // expires_at extended to ~72h from now
        $this->assertGreaterThan(now()->addHours(70), $row->expires_at);
    }

    public function test_mark_promoted_freezes_row(): void
    {
        $row = PrepResource::factory()->create(['status' => PrepResource::STATUS_SIGNED]);
        $repo = new PrepResourceRepository();
        $permId = (string) Str::uuid();

        $repo->markPromoted($row, permanentResourceId: $permId);
        $row->refresh();

        $this->assertEquals(PrepResource::STATUS_PROMOTED, $row->status);
        $this->assertEquals($permId, $row->permanent_resource_id);
        $this->assertNotNull($row->promoted_at);
    }

    public function test_find_expired_returns_only_gc_eligible(): void
    {
        Carbon::setTestNow('2026-05-27T12:00:00Z');
        PrepResource::factory()->create(['status' => PrepResource::STATUS_PREPARED, 'expires_at' => now()->subHour()]);
        PrepResource::factory()->create(['status' => PrepResource::STATUS_SIGNED,   'expires_at' => now()->subHour()]);
        PrepResource::factory()->create(['status' => PrepResource::STATUS_PROMOTED, 'expires_at' => now()->subHour()]); // immortal
        PrepResource::factory()->create(['status' => PrepResource::STATUS_PREPARED, 'expires_at' => now()->addHour()]); // not yet expired

        $repo = new PrepResourceRepository();
        $expired = $repo->findExpired(limit: 100);
        $this->assertCount(2, $expired);

        Carbon::setTestNow();
    }

    public function test_find_by_idempotency_returns_match(): void
    {
        $sourceId = (string) Str::uuid();
        PrepResource::factory()->create(['source_service_id' => $sourceId, 'idempotency_key' => 'k1']);

        $repo = new PrepResourceRepository();
        $row = $repo->findByIdempotency($sourceId, 'k1');
        $this->assertNotNull($row);
        $this->assertNull($repo->findByIdempotency($sourceId, 'nope'));
    }
}
```

### Step 2 — Run, expect failure

```bash
vendor/bin/phpunit tests/Unit/Sharing/Prep/PrepResourceRepositoryTest.php
```

### Step 3 — Implement

```php
<?php
// src/Sharing/Prep/PrepResourceRepository.php
namespace AuthService\Helper\Sharing\Prep;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PrepResourceRepository
{
    /**
     * Upsert by (source_service_id, idempotency_key). Returns existing row
     * if one exists for this idempotency_key, never duplicates.
     */
    public function upsert(
        string $sourceServiceId,
        string $idempotencyKey,
        string $intent,
        string $intentVersion,
        array $sourceResource,
        array $studentData,
        array $payload,
        ?string $returnTo,
        int $ttlPreparedHours,
    ): PrepResource {
        $existing = $this->findByIdempotency($sourceServiceId, $idempotencyKey);
        if ($existing) {
            return $existing;
        }

        return PrepResource::create([
            'id'                 => (string) Str::uuid(),
            'source_service_id'  => $sourceServiceId,
            'idempotency_key'    => $idempotencyKey,
            'intent'             => $intent,
            'intent_version'     => $intentVersion,
            'source_resource'    => $sourceResource,
            'student_data'       => $studentData,
            'payload'            => $payload,
            'signed_data'        => null,
            'return_to'          => $returnTo,
            'status'             => PrepResource::STATUS_PREPARED,
            'prepared_at'        => Carbon::now(),
            'expires_at'         => Carbon::now()->addHours($ttlPreparedHours),
        ]);
    }

    public function findByIdempotency(string $sourceServiceId, string $idempotencyKey): ?PrepResource
    {
        return PrepResource::query()
            ->where('source_service_id', $sourceServiceId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    public function find(string $prepId): ?PrepResource
    {
        return PrepResource::query()->find($prepId);
    }

    /**
     * Flip prepared → signed. Extends TTL via ttlSignedHours.
     */
    public function markSigned(PrepResource $row, array $signedData, int $ttlSignedHours): void
    {
        $row->forceFill([
            'status'      => PrepResource::STATUS_SIGNED,
            'signed_data' => $signedData,
            'signed_at'   => Carbon::now(),
            'expires_at'  => Carbon::now()->addHours($ttlSignedHours),
        ])->save();
    }

    /**
     * Flip signed → promoted. Row is now immortal (expires_at preserved
     * for audit but no longer GC-eligible because status is terminal).
     */
    public function markPromoted(PrepResource $row, string $permanentResourceId): void
    {
        $row->forceFill([
            'status'                => PrepResource::STATUS_PROMOTED,
            'permanent_resource_id' => $permanentResourceId,
            'promoted_at'           => Carbon::now(),
        ])->save();
    }

    /**
     * Rows eligible for GC: prepared/signed/expired AND expires_at past now.
     * promoted rows are NEVER returned.
     */
    public function findExpired(int $limit = 500): Collection
    {
        return PrepResource::query()
            ->whereIn('status', PrepResource::GC_ELIGIBLE_STATES)
            ->where('expires_at', '<', Carbon::now())
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();
    }

    public function delete(PrepResource $row): void
    {
        $row->delete();
    }
}
```

### Step 4 — Run + commit

```bash
vendor/bin/phpunit tests/Unit/Sharing/Prep/PrepResourceRepositoryTest.php
# Expected: 6 passed.

git add src/Sharing/Prep/PrepResourceRepository.php \
        tests/Unit/Sharing/Prep/PrepResourceRepositoryTest.php
git commit -m "feat(sharing): phase B2 — PrepResourceRepository

Centralises all state transitions on prep_resources: upsert (idempotent
on (source_service_id, idempotency_key)), markSigned (extends TTL +
captures signed_data), markPromoted (freezes row, sets
permanent_resource_id), findExpired (excludes terminal promoted rows).

Phase: B2 of docs/plans/PrepSignPromote-2026-05-27/"
```

## Verification checklist

- [ ] `upsert` returns existing row on duplicate key — never creates a second
- [ ] `markSigned` updates status + signed_at + signed_data + extends expires_at
- [ ] `markPromoted` sets permanent_resource_id; row no longer GC-eligible
- [ ] `findExpired` returns prepared/signed/expired rows past `now`; never promoted
