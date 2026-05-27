<?php

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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
            'expires_at' => now()->addHour(),
        ]);
        $repo = new PrepResourceRepository();

        $repo->markSigned($row, signedData: ['signature' => 'Jane Doe', 'ip' => '1.2.3.4'], ttlSignedHours: 72);
        $row->refresh();

        $this->assertEquals(PrepResource::STATUS_SIGNED, $row->status);
        $this->assertNotNull($row->signed_at);
        $this->assertEquals(['signature' => 'Jane Doe', 'ip' => '1.2.3.4'], $row->signed_data);
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
        PrepResource::factory()->create(['status' => PrepResource::STATUS_PROMOTED, 'expires_at' => now()->subHour()]);
        PrepResource::factory()->create(['status' => PrepResource::STATUS_PREPARED, 'expires_at' => now()->addHour()]);

        $repo = new PrepResourceRepository();
        $expired = $repo->findExpired(limit: 100);
        $this->assertCount(2, $expired);
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
