<?php

namespace Tests\Unit\Sharing\Inbox;

use AuthService\Helper\Sharing\Inbox\InboundShareMessage;
use Database\Factories\InboundShareMessageFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

class InboundShareMessageTest extends TestCase
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
        $this->assertTrue(Schema::hasTable('inbound_share_messages'));
        foreach ([
            'id', 'envelope_version', 'message_id', 'correlation_id',
            'intent', 'intent_version',
            'source_service_id', 'target_service_id', 'user_id',
            'idempotency_key', 'issued_at', 'payload',
            'signature_header', 'headers',
            'received_at', 'processing_status', 'processing_error',
            'dispatched_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('inbound_share_messages', $col),
                "inbound_share_messages missing column: {$col}"
            );
        }
    }

    public function test_unique_source_idempotency_key_index_exists(): void
    {
        $indexes = collect(Schema::getIndexes('inbound_share_messages'));
        $this->assertTrue(
            $indexes->contains(fn ($i) =>
                $i['unique'] === true
                && in_array('source_service_id', $i['columns'], true)
                && in_array('idempotency_key', $i['columns'], true)
            ),
            'Expected UNIQUE(source_service_id, idempotency_key) index'
        );
    }

    public function test_factory_creates_row_with_received_status(): void
    {
        $row = InboundShareMessageFactory::new()->create();
        $this->assertInstanceOf(InboundShareMessage::class, $row);
        $this->assertEquals('received', $row->processing_status);
        $this->assertIsArray($row->payload);
        $this->assertIsArray($row->headers);
    }

    public function test_helper_methods_isDispatched_and_isDuplicate(): void
    {
        $dispatched = InboundShareMessageFactory::new()->create([
            'processing_status' => 'dispatched',
        ]);
        $dup = InboundShareMessageFactory::new()->create([
            'processing_status' => 'duplicate',
        ]);

        $this->assertTrue($dispatched->isDispatched());
        $this->assertFalse($dispatched->isDuplicate());
        $this->assertTrue($dup->isDuplicate());
        $this->assertFalse($dup->isDispatched());
    }
}
