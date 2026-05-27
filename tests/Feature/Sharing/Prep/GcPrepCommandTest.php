<?php

namespace Tests\Feature\Sharing\Prep;

use AuthService\Helper\Sharing\Prep\Events\PrepResourceExpired;
use AuthService\Helper\Sharing\Prep\PrepResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;

class GcPrepCommandTest extends TestCase
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

    public function test_sweeps_only_expired_non_promoted_rows(): void
    {
        Event::fake([PrepResourceExpired::class]);

        $expired1 = PrepResource::factory()->create(['status' => 'prepared', 'expires_at' => now()->subHour()]);
        $expired2 = PrepResource::factory()->create(['status' => 'signed',   'expires_at' => now()->subHour()]);
        $promoted = PrepResource::factory()->create(['status' => 'promoted', 'expires_at' => now()->subYear(), 'promoted_at' => now()->subYear(), 'permanent_resource_id' => 'p-1']);
        $future   = PrepResource::factory()->create(['status' => 'prepared', 'expires_at' => now()->addHour()]);

        $this->artisan('sharing:gc-prep')->assertExitCode(0);

        $this->assertNull(PrepResource::find($expired1->id), 'expected expired prepared row to be deleted');
        $this->assertNull(PrepResource::find($expired2->id), 'expected expired signed row to be deleted');
        $this->assertNotNull(PrepResource::find($promoted->id), 'promoted rows must NEVER be deleted');
        $this->assertNotNull(PrepResource::find($future->id), 'non-expired rows must stay');

        Event::assertDispatched(PrepResourceExpired::class, 2);
    }

    public function test_dry_run_does_not_delete(): void
    {
        PrepResource::factory()->create(['status' => 'prepared', 'expires_at' => now()->subHour()]);
        $this->artisan('sharing:gc-prep', ['--dry-run' => true])->assertExitCode(0);
        $this->assertEquals(1, PrepResource::count(), 'dry-run must not delete');
    }

    public function test_batch_caps_sweep_size(): void
    {
        for ($i = 0; $i < 5; $i++) {
            PrepResource::factory()->create(['status' => 'prepared', 'expires_at' => now()->subHour()]);
        }
        $this->artisan('sharing:gc-prep', ['--batch' => 2])->assertExitCode(0);
        $this->assertEquals(3, PrepResource::count(), 'should only delete 2 per --batch=2');
    }
}
