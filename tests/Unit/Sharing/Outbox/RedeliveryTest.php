<?php

namespace Tests\Unit\Sharing\Outbox;

use AuthService\Helper\Sharing\Outbox\Exceptions\RedeliveryNotPermittedException;
use AuthService\Helper\Sharing\Outbox\Jobs\DispatchOutboundShareJob;
use AuthService\Helper\Sharing\Outbox\OutboundShareMessage;
use AuthService\Helper\Sharing\SharingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;

class RedeliveryTest extends TestCase
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

    public function test_redeliver_dead_lettered_row_resets_state_and_dispatches_job(): void
    {
        Queue::fake();

        $row = OutboundShareMessage::factory()->create([
            'status' => OutboundShareMessage::STATUS_DEAD_LETTERED,
            'attempts' => 6,
            'dead_lettered_at' => now()->subHour(),
            'last_error' => 'previous failure',
        ]);

        $sharing = $this->app->make(SharingService::class);
        $sharing->redeliver($row->id);

        $row->refresh();
        $this->assertEquals(OutboundShareMessage::STATUS_QUEUED, $row->status);
        $this->assertEquals(0, $row->attempts);
        $this->assertNull($row->dead_lettered_at);
        $this->assertNull($row->next_retry_at);

        Queue::assertPushed(
            DispatchOutboundShareJob::class,
            fn ($job) => $job->outboundMessageId === $row->id,
        );
    }

    public function test_redeliver_delivered_row_throws(): void
    {
        $row = OutboundShareMessage::factory()->create([
            'status' => OutboundShareMessage::STATUS_DELIVERED,
        ]);

        $sharing = $this->app->make(SharingService::class);
        $this->expectException(RedeliveryNotPermittedException::class);
        $sharing->redeliver($row->id);
    }

    public function test_redeliver_failed_permanent_row_throws(): void
    {
        $row = OutboundShareMessage::factory()->create([
            'status' => OutboundShareMessage::STATUS_FAILED_PERMANENT,
        ]);

        $sharing = $this->app->make(SharingService::class);
        $this->expectException(RedeliveryNotPermittedException::class);
        $sharing->redeliver($row->id);
    }

    public function test_redeliver_queued_row_throws_already_pending(): void
    {
        $row = OutboundShareMessage::factory()->create([
            'status' => OutboundShareMessage::STATUS_QUEUED,
        ]);

        $sharing = $this->app->make(SharingService::class);
        $this->expectException(RedeliveryNotPermittedException::class);
        $sharing->redeliver($row->id);
    }

    public function test_redeliver_missing_row_throws(): void
    {
        $sharing = $this->app->make(SharingService::class);
        $this->expectException(RedeliveryNotPermittedException::class);
        $sharing->redeliver('11111111-1111-1111-1111-111111111111');
    }

    public function test_list_failed_returns_dead_lettered_and_failed_permanent(): void
    {
        OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_DEAD_LETTERED]);
        OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_DEAD_LETTERED]);
        OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_FAILED_PERMANENT]);
        OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_DELIVERED]);
        OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_QUEUED]);

        $sharing = $this->app->make(SharingService::class);
        $failed = $sharing->listFailed();

        $this->assertCount(3, $failed);
        foreach ($failed as $row) {
            $this->assertContains($row->status, [
                OutboundShareMessage::STATUS_DEAD_LETTERED,
                OutboundShareMessage::STATUS_FAILED_PERMANENT,
            ]);
        }
    }
}
