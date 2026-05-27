<?php

namespace Tests\Unit\Sharing\Outbox;

use AuthService\Helper\Sharing\Outbox\DeliveryStatusSummary;
use AuthService\Helper\Sharing\Outbox\OutboundShareMessage;
use AuthService\Helper\Sharing\SharingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase;

class DeliveryStatusSummaryTest extends TestCase
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

    public function test_get_delivery_status_aggregates_by_state(): void
    {
        $shareId = (string) Str::uuid();
        Carbon::setTestNow('2026-05-27T12:00:00Z');

        OutboundShareMessage::factory()->create([
            'share_id' => $shareId,
            'status' => OutboundShareMessage::STATUS_QUEUED,
        ]);
        OutboundShareMessage::factory()->create([
            'share_id' => $shareId,
            'status' => OutboundShareMessage::STATUS_RETRY_SCHEDULED,
        ]);
        OutboundShareMessage::factory()->create([
            'share_id' => $shareId,
            'status' => OutboundShareMessage::STATUS_DELIVERED,
            'delivered_at' => Carbon::now()->subMinute(),
            'last_attempt_at' => Carbon::now()->subMinute(),
        ]);
        OutboundShareMessage::factory()->create([
            'share_id' => $shareId,
            'status' => OutboundShareMessage::STATUS_FAILED_PERMANENT,
            'last_attempt_at' => Carbon::now()->subMinutes(2),
        ]);
        OutboundShareMessage::factory()->create([
            'share_id' => $shareId,
            'status' => OutboundShareMessage::STATUS_DEAD_LETTERED,
        ]);

        // unrelated share — must not bleed into counts
        OutboundShareMessage::factory()->create([
            'share_id' => (string) Str::uuid(),
            'status' => OutboundShareMessage::STATUS_DELIVERED,
        ]);

        /** @var SharingService $sharing */
        $sharing = $this->app->make(SharingService::class);
        $summary = $sharing->getDeliveryStatus($shareId);

        $this->assertInstanceOf(DeliveryStatusSummary::class, $summary);
        $this->assertEquals($shareId, $summary->shareId);
        $this->assertEquals(2, $summary->pending);       // queued + retry_scheduled
        $this->assertEquals(1, $summary->delivered);
        $this->assertEquals(1, $summary->failed);
        $this->assertEquals(1, $summary->deadLettered);
        $this->assertNotNull($summary->lastDeliveredAt);
        $this->assertNotNull($summary->lastAttemptAt);

        $array = $summary->toArray();
        $this->assertEquals($shareId, $array['share_id']);
        $this->assertEquals(2, $array['pending']);
    }

    public function test_get_delivery_status_for_unknown_share_returns_zeroes(): void
    {
        $sharing = $this->app->make(SharingService::class);
        $summary = $sharing->getDeliveryStatus((string) Str::uuid());

        $this->assertEquals(0, $summary->pending);
        $this->assertEquals(0, $summary->delivered);
        $this->assertEquals(0, $summary->failed);
        $this->assertEquals(0, $summary->deadLettered);
        $this->assertNull($summary->lastDeliveredAt);
    }
}
