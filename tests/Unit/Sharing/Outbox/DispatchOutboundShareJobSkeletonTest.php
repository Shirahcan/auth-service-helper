<?php

namespace Tests\Unit\Sharing\Outbox;

use AuthService\Helper\Sharing\Outbox\Jobs\DispatchOutboundShareJob;
use AuthService\Helper\Sharing\Outbox\OutboundShareMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class DispatchOutboundShareJobSkeletonTest extends TestCase
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
    }

    public function test_2xx_response_marks_delivered_and_writes_signature_header(): void
    {
        Http::fake([
            'portify.test/*' => Http::response(['ok' => true], 202),
        ]);

        $row = OutboundShareMessage::factory()->create([
            'peer_slug' => 'portify',
            'status' => OutboundShareMessage::STATUS_QUEUED,
            'attempts' => 0,
        ]);

        (new DispatchOutboundShareJob($row->id))->handle();

        $row->refresh();
        $this->assertEquals(OutboundShareMessage::STATUS_DELIVERED, $row->status);
        $this->assertEquals(1, $row->attempts);
        $this->assertNotNull($row->delivered_at);
        $this->assertNotNull($row->signature_header);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->hasHeader('X-Signature')
                && $request->hasHeader('X-Trust-Key', 'tk')
                && $request->hasHeader('X-Idempotency-Key');
        });
    }

    public function test_non_2xx_response_schedules_retry_in_skeleton(): void
    {
        Http::fake([
            'portify.test/*' => Http::response(['err' => 'down'], 503),
        ]);

        $row = OutboundShareMessage::factory()->create([
            'peer_slug' => 'portify',
            'status' => OutboundShareMessage::STATUS_QUEUED,
            'attempts' => 0,
        ]);

        (new DispatchOutboundShareJob($row->id))->handle();

        $row->refresh();
        $this->assertEquals(OutboundShareMessage::STATUS_RETRY_SCHEDULED, $row->status);
        $this->assertEquals(1, $row->attempts);
        $this->assertEquals(503, $row->last_response_status);
        $this->assertNotNull($row->next_retry_at);
    }

    public function test_terminal_row_is_skipped(): void
    {
        Http::fake();

        $row = OutboundShareMessage::factory()->create([
            'peer_slug' => 'portify',
            'status' => OutboundShareMessage::STATUS_DELIVERED,
            'attempts' => 1,
        ]);

        (new DispatchOutboundShareJob($row->id))->handle();

        Http::assertNothingSent();
        $row->refresh();
        $this->assertEquals(OutboundShareMessage::STATUS_DELIVERED, $row->status);
        $this->assertEquals(1, $row->attempts);
    }

    public function test_missing_row_is_a_noop(): void
    {
        Http::fake();
        (new DispatchOutboundShareJob('11111111-1111-1111-1111-111111111111'))->handle();
        Http::assertNothingSent();
    }
}
