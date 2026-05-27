<?php

namespace Tests\Unit\Sharing\Outbox;

use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use AuthService\Helper\Sharing\Outbox\OutboundShareMessage;
use AuthService\Helper\Sharing\Outbox\SharingOutboxRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Orchestra\Testbench\TestCase;

class SharingOutboxRepositoryTest extends TestCase
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

    public function test_enqueue_inserts_queued_row(): void
    {
        $repo = new SharingOutboxRepository();
        $env = $this->envelope();

        $row = $repo->enqueue($env, peerSlug: 'portify');

        $this->assertEquals(OutboundShareMessage::STATUS_QUEUED, $row->status);
        $this->assertEquals('portify', $row->peer_slug);
        $this->assertEquals($env->idempotencyKey, $row->idempotency_key);
        $this->assertEquals($env->intent, $row->intent);
        $this->assertEquals(0, $row->attempts);
    }

    public function test_enqueue_is_idempotent_by_peer_and_key(): void
    {
        $repo = new SharingOutboxRepository();
        $env = $this->envelope();

        $first = $repo->enqueue($env, peerSlug: 'portify');
        $second = $repo->enqueue($env, peerSlug: 'portify');

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, OutboundShareMessage::query()->count());
    }

    public function test_find_deliverable_returns_due_rows_only(): void
    {
        $repo = new SharingOutboxRepository();
        $due = OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_RETRY_SCHEDULED, 'next_retry_at' => Carbon::now()->subMinute()]);
        OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_RETRY_SCHEDULED, 'next_retry_at' => Carbon::now()->addHour()]);
        OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_DELIVERED]);
        $queued = OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_QUEUED, 'next_retry_at' => null]);

        $ids = $repo->findDeliverable(limit: 25)->pluck('id')->all();
        $this->assertContains($due->id, $ids);
        $this->assertContains($queued->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_mark_in_flight_bumps_attempts(): void
    {
        $repo = new SharingOutboxRepository();
        $row = OutboundShareMessage::factory()->create(['attempts' => 1]);

        $repo->markInFlight($row);

        $row->refresh();
        $this->assertEquals(OutboundShareMessage::STATUS_IN_FLIGHT, $row->status);
        $this->assertEquals(2, $row->attempts);
        $this->assertNotNull($row->last_attempt_at);
    }

    public function test_mark_delivered_sets_terminal_state(): void
    {
        $repo = new SharingOutboxRepository();
        $row = OutboundShareMessage::factory()->create([
            'status' => OutboundShareMessage::STATUS_IN_FLIGHT,
        ]);

        $repo->markDelivered($row);

        $row->refresh();
        $this->assertEquals(OutboundShareMessage::STATUS_DELIVERED, $row->status);
        $this->assertNotNull($row->delivered_at);
    }

    public function test_schedule_retry_writes_next_retry_at(): void
    {
        $repo = new SharingOutboxRepository();
        $row = OutboundShareMessage::factory()->create([
            'status' => OutboundShareMessage::STATUS_IN_FLIGHT,
            'attempts' => 1,
        ]);

        $repo->scheduleRetry($row, attemptN: 1, responseStatus: 503, error: 'upstream 503');
        $row->refresh();

        $this->assertEquals(OutboundShareMessage::STATUS_RETRY_SCHEDULED, $row->status);
        $this->assertNotNull($row->next_retry_at);
        $this->assertEquals(503, $row->last_response_status);
        $this->assertSame('upstream 503', $row->last_error);
    }

    public function test_dead_letter_terminal(): void
    {
        $repo = new SharingOutboxRepository();
        $row = OutboundShareMessage::factory()->create([
            'status' => OutboundShareMessage::STATUS_IN_FLIGHT,
        ]);

        $repo->deadLetter($row, error: 'attempts exhausted');
        $row->refresh();

        $this->assertEquals(OutboundShareMessage::STATUS_DEAD_LETTERED, $row->status);
        $this->assertNotNull($row->dead_lettered_at);
        $this->assertSame('attempts exhausted', $row->last_error);
    }

    public function test_get_by_share_id_returns_collection(): void
    {
        $shareId = (string) \Illuminate\Support\Str::uuid();
        OutboundShareMessage::factory()->count(3)->create(['share_id' => $shareId]);
        OutboundShareMessage::factory()->create(['share_id' => (string) \Illuminate\Support\Str::uuid()]);

        $repo = new SharingOutboxRepository();
        $this->assertCount(3, $repo->getByShareId($shareId));
    }

    public function test_get_by_message_id_returns_single(): void
    {
        $row = OutboundShareMessage::factory()->create();
        $repo = new SharingOutboxRepository();

        $this->assertEquals($row->id, $repo->getByMessageId($row->id)->id);
    }

    private function envelope(): ShareEnvelope
    {
        return ShareEnvelope::fromArray([
            'envelope_version' => '1',
            'message_id' => 'msg_01HZ',
            'correlation_id' => '00000000-0000-0000-0000-000000000001',
            'intent' => 'service_purchase',
            'intent_version' => '1.0',
            'source_service_id' => '00000000-0000-0000-0000-000000000001',
            'target_service_id' => '00000000-0000-0000-0000-000000000002',
            'user_id' => '00000000-0000-0000-0000-000000000003',
            'idempotency_key' => 'studendly:order:88421',
            'issued_at' => '2026-05-27T10:00:00Z',
            'payload' => ['order_id' => 'x'],
        ]);
    }
}
