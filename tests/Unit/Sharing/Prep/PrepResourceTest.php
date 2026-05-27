<?php

namespace Tests\Unit\Sharing\Prep;

use AuthService\Helper\Sharing\Prep\PrepResource;
use Database\Factories\PrepResourceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

class PrepResourceTest extends TestCase
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

    public function test_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('prep_resources'));
        foreach ([
            'id', 'source_service_id', 'idempotency_key', 'intent', 'intent_version',
            'source_resource', 'student_data', 'payload', 'signed_data', 'return_to',
            'status', 'prepared_at', 'signed_at', 'promoted_at', 'expires_at',
            'permanent_resource_id', 'created_at', 'updated_at',
        ] as $col) {
            $this->assertTrue(Schema::hasColumn('prep_resources', $col), "missing column: {$col}");
        }
    }

    public function test_unique_idempotency_index_exists(): void
    {
        $indexes = collect(Schema::getIndexes('prep_resources'));
        $this->assertTrue(
            $indexes->contains(fn ($i) =>
                $i['unique']
                && in_array('source_service_id', $i['columns'], true)
                && in_array('idempotency_key', $i['columns'], true)
            ),
        );
    }

    public function test_factory_defaults_to_prepared_status(): void
    {
        $row = PrepResourceFactory::new()->create();
        $this->assertEquals(PrepResource::STATUS_PREPARED, $row->status);
        $this->assertNotNull($row->prepared_at);
        $this->assertNull($row->signed_at);
        $this->assertNull($row->promoted_at);
        $this->assertNotNull($row->expires_at);
    }

    public function test_state_predicates(): void
    {
        $prepared = PrepResourceFactory::new()->create();
        $signed   = PrepResourceFactory::new()->create(['status' => PrepResource::STATUS_SIGNED]);
        $promoted = PrepResourceFactory::new()->create(['status' => PrepResource::STATUS_PROMOTED]);

        $this->assertTrue($prepared->isPrepared());
        $this->assertFalse($prepared->isSigned());

        $this->assertTrue($signed->isSigned());
        $this->assertFalse($signed->isPromoted());

        $this->assertTrue($promoted->isPromoted());
        $this->assertTrue($promoted->isTerminal());
        $this->assertFalse($prepared->isTerminal());
    }
}
