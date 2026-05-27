<?php

namespace Tests\Unit\Sharing\Outbox;

use AuthService\Helper\Sharing\Outbox\Jobs\DispatchOutboundShareJob;
use AuthService\Helper\Sharing\Outbox\OutboundShareMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class DispatchOutboundShareJobRetryTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('authservice.sharing.peers.portify', [
            'webhook_url' => 'https://portify.test/api/v1/inbound/user-share',
            'signing_secret' => 'shh',
            'trust_key' => 'tk',
            'target_service_id' => '00000000-0000-0000-0000-000000000002',
        ]);
        Carbon::setTestNow('2026-05-27T12:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_5xx_on_first_attempt_schedules_1min(): void
    {
        Http::fake(['portify.test/*' => Http::response('', 503)]);
        $row = OutboundShareMessage::factory()->create([
            'peer_slug' => 'portify',
            'status' => OutboundShareMessage::STATUS_QUEUED,
            'attempts' => 0,
        ]);

        (new DispatchOutboundShareJob($row->id))->handle();
        $row->refresh();

        $this->assertEquals(OutboundShareMessage::STATUS_RETRY_SCHEDULED, $row->status);
        $this->assertEquals(1, $row->attempts);
        $this->assertEquals(
            Carbon::now()->addSeconds(60)->timestamp,
            $row->next_retry_at->timestamp,
        );
    }

    public function test_5xx_on_second_attempt_schedules_5min(): void
    {
        Http::fake(['portify.test/*' => Http::response('', 503)]);
        $row = OutboundShareMessage::factory()->create([
            'peer_slug' => 'portify',
            'status' => OutboundShareMessage::STATUS_RETRY_SCHEDULED,
            'attempts' => 1,
            'next_retry_at' => Carbon::now()->subMinute(),
        ]);

        (new DispatchOutboundShareJob($row->id))->handle();
        $row->refresh();

        $this->assertEquals(OutboundShareMessage::STATUS_RETRY_SCHEDULED, $row->status);
        $this->assertEquals(2, $row->attempts);
        $this->assertEquals(
            Carbon::now()->addSeconds(300)->timestamp,
            $row->next_retry_at->timestamp,
        );
    }

    public function test_5xx_after_five_attempts_dead_letters(): void
    {
        Http::fake(['portify.test/*' => Http::response('', 503)]);
        $row = OutboundShareMessage::factory()->create([
            'peer_slug' => 'portify',
            'status' => OutboundShareMessage::STATUS_RETRY_SCHEDULED,
            'attempts' => 5,
            'next_retry_at' => Carbon::now()->subMinute(),
        ]);

        (new DispatchOutboundShareJob($row->id))->handle();
        $row->refresh();

        $this->assertEquals(OutboundShareMessage::STATUS_DEAD_LETTERED, $row->status);
        $this->assertEquals(6, $row->attempts);
        $this->assertNotNull($row->dead_lettered_at);
    }

    public function test_4xx_is_failed_permanent_immediately(): void
    {
        Http::fake(['portify.test/*' => Http::response('', 422)]);
        $row = OutboundShareMessage::factory()->create([
            'peer_slug' => 'portify',
            'status' => OutboundShareMessage::STATUS_QUEUED,
            'attempts' => 0,
        ]);

        (new DispatchOutboundShareJob($row->id))->handle();
        $row->refresh();

        $this->assertEquals(OutboundShareMessage::STATUS_FAILED_PERMANENT, $row->status);
        $this->assertEquals(1, $row->attempts);
        $this->assertEquals(422, $row->last_response_status);
    }

    public function test_429_is_retried_not_failed_permanent(): void
    {
        Http::fake(['portify.test/*' => Http::response('', 429)]);
        $row = OutboundShareMessage::factory()->create([
            'peer_slug' => 'portify',
            'status' => OutboundShareMessage::STATUS_QUEUED,
            'attempts' => 0,
        ]);

        (new DispatchOutboundShareJob($row->id))->handle();
        $row->refresh();

        $this->assertEquals(OutboundShareMessage::STATUS_RETRY_SCHEDULED, $row->status);
    }
}
