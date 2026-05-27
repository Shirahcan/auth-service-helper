<?php

namespace Tests\Feature\Sharing\Console;

use AuthService\Helper\Sharing\Outbox\OutboundShareMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Orchestra\Testbench\TestCase;

class PurgeDeliveredCommandTest extends TestCase
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

    public function test_purges_delivered_rows_older_than_default_30_days(): void
    {
        Carbon::setTestNow('2026-05-27T12:00:00Z');

        $old = OutboundShareMessage::factory()->create([
            'status' => OutboundShareMessage::STATUS_DELIVERED,
            'delivered_at' => Carbon::now()->subDays(45),
        ]);
        $recent = OutboundShareMessage::factory()->create([
            'status' => OutboundShareMessage::STATUS_DELIVERED,
            'delivered_at' => Carbon::now()->subDays(10),
        ]);
        $deadOld = OutboundShareMessage::factory()->create([
            'status' => OutboundShareMessage::STATUS_DEAD_LETTERED,
            'updated_at' => Carbon::now()->subDays(45),
        ]);

        $this->artisan('sharing:purge-delivered')->assertExitCode(0);

        $this->assertNull(OutboundShareMessage::find($old->id));
        $this->assertNotNull(OutboundShareMessage::find($recent->id));
        $this->assertNotNull(OutboundShareMessage::find($deadOld->id), 'non-delivered rows must not be purged');
    }

    public function test_respects_before_flag(): void
    {
        Carbon::setTestNow('2026-05-27T12:00:00Z');

        $row = OutboundShareMessage::factory()->create([
            'status' => OutboundShareMessage::STATUS_DELIVERED,
            'delivered_at' => Carbon::parse('2026-04-01T00:00:00Z'),
        ]);

        $this->artisan('sharing:purge-delivered', ['--before' => '2026-04-15'])
            ->assertExitCode(0);

        $this->assertNull(OutboundShareMessage::find($row->id));
    }

    public function test_invalid_before_value_fails_with_non_zero_exit(): void
    {
        $this->artisan('sharing:purge-delivered', ['--before' => 'not-a-date'])
            ->assertExitCode(1);
    }
}
