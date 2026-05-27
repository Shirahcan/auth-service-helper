<?php

namespace Tests\Unit\Sharing\Outbox;

use AuthService\Helper\Sharing\Outbox\OutboundShareMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

class OutboundShareMessageTest extends TestCase
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

    public function test_table_exists_with_required_columns(): void
    {
        $this->assertTrue(Schema::hasTable('outbound_share_messages'));

        foreach ([
            'id', 'share_id', 'target_service_id', 'peer_slug',
            'intent', 'intent_version', 'idempotency_key',
            'envelope_json', 'signature_header',
            'status', 'attempts', 'last_attempt_at', 'next_retry_at',
            'delivered_at', 'dead_lettered_at',
            'last_error', 'last_response_status',
            'created_at', 'updated_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('outbound_share_messages', $col),
                "outbound_share_messages missing column: {$col}",
            );
        }
    }

    public function test_unique_peer_slug_and_idempotency_key(): void
    {
        $indexes = collect(Schema::getIndexes('outbound_share_messages'));
        $this->assertTrue(
            $indexes->contains(
                fn ($i) => $i['unique']
                    && in_array('peer_slug', $i['columns'])
                    && in_array('idempotency_key', $i['columns']),
            ),
            'UNIQUE(peer_slug, idempotency_key) index missing',
        );
    }

    public function test_factory_persists_with_defaults(): void
    {
        $row = OutboundShareMessage::factory()->create();

        $this->assertNotNull($row->id);
        $this->assertEquals(OutboundShareMessage::STATUS_QUEUED, $row->status);
        $this->assertEquals(0, $row->attempts);
        $this->assertIsArray($row->envelope_json);
    }

    public function test_state_predicates(): void
    {
        $queued = OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_QUEUED]);
        $retry  = OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_RETRY_SCHEDULED]);
        $inflight = OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_IN_FLIGHT]);
        $delivered = OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_DELIVERED]);
        $dlq = OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_DEAD_LETTERED]);
        $perm = OutboundShareMessage::factory()->create(['status' => OutboundShareMessage::STATUS_FAILED_PERMANENT]);

        $this->assertTrue($queued->isDeliverable());
        $this->assertTrue($retry->isDeliverable());
        $this->assertFalse($inflight->isDeliverable());
        $this->assertFalse($delivered->isDeliverable());

        $this->assertFalse($queued->isInTerminalState());
        $this->assertTrue($delivered->isInTerminalState());
        $this->assertTrue($dlq->isInTerminalState());
        $this->assertTrue($perm->isInTerminalState());
    }
}
