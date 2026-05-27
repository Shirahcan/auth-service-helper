<?php

namespace Tests\Feature\Sharing\Inbox;

use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use AuthService\Helper\Sharing\Inbox\Events\InboundShareReceived;
use AuthService\Helper\Sharing\Inbox\Http\Controllers\InboundShareWebhookController;
use AuthService\Helper\Sharing\Inbox\InboundShareMessage;
use AuthService\Helper\Sharing\Inbox\Queries\Sharing;
use AuthService\Helper\Sharing\Intents\Contracts\SharePayload;
use AuthService\Helper\Sharing\Intents\IntentRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;

class InboundShareWebhookValidationTest extends TestCase
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
        $schemaPath = __DIR__ . '/fixtures/test-intent.schema.json';
        if (!file_exists(dirname($schemaPath))) {
            mkdir(dirname($schemaPath), 0777, true);
        }
        file_put_contents($schemaPath, json_encode([
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'required' => ['order_id'],
            'properties' => ['order_id' => ['type' => 'string']],
            'additionalProperties' => true,
        ]));

        /** @var IntentRegistry $reg */
        $reg = $this->app->make(IntentRegistry::class);
        $reg->register('test.purchase', TestPurchasePayload::class, schemaPath: $schemaPath);
    }

    public function test_valid_payload_dispatches_event_and_marks_dispatched(): void
    {
        Event::fake([InboundShareReceived::class]);
        $env = $this->fakeEnv(['order_id' => 'ord_123']);

        $resp = $this->controller()->receive($this->request($env));
        $this->assertEquals(202, $resp->getStatusCode());

        $row = InboundShareMessage::where('message_id', $env->messageId)->firstOrFail();
        $this->assertEquals('dispatched', $row->processing_status);
        $this->assertNotNull($row->dispatched_at);

        Event::assertDispatched(
            InboundShareReceived::class,
            fn (InboundShareReceived $e) =>
                $e->shareId === $env->correlationId
                && $e->intent === 'test.purchase'
                && $e->payload instanceof TestPurchasePayload
                && $e->payload->orderId === 'ord_123'
        );
    }

    public function test_invalid_payload_returns_422_and_marks_rejected_schema(): void
    {
        Event::fake([InboundShareReceived::class]);
        $env = $this->fakeEnv(['wrong_field' => 'x']); // missing required order_id

        $resp = $this->controller()->receive($this->request($env));
        $this->assertEquals(422, $resp->getStatusCode());

        $row = InboundShareMessage::where('message_id', $env->messageId)->firstOrFail();
        $this->assertEquals('rejected_schema', $row->processing_status);
        $this->assertNotNull($row->processing_error);

        Event::assertNotDispatched(InboundShareReceived::class);
    }

    public function test_last_inbound_for_query_helper(): void
    {
        $env1 = $this->fakeEnv(['order_id' => 'a']);
        $this->controller()->receive($this->request($env1));
        // Sleep ≥1 second so received_at differs at second precision (the
        // migration uses $table->timestamp(...), default Laravel seconds).
        sleep(1);
        $env2 = ShareEnvelope::fromArray(array_merge($env1->toArray(), [
            'message_id' => 'msg_NEW',
            'idempotency_key' => 'different-key',
            'payload' => ['order_id' => 'b'],
        ]));
        $this->controller()->receive($this->request($env2));

        $latest = Sharing::lastInboundFor($env1->correlationId);
        $this->assertNotNull($latest);
        $this->assertEquals('msg_NEW', $latest->message_id);
    }

    private function controller(): InboundShareWebhookController
    {
        return $this->app->make(InboundShareWebhookController::class);
    }

    private function fakeEnv(array $payload): ShareEnvelope
    {
        return ShareEnvelope::fromArray([
            'envelope_version' => '1',
            'message_id' => 'msg_' . bin2hex(random_bytes(8)),
            'correlation_id' => '00000000-0000-0000-0000-000000000010',
            'intent' => 'test.purchase',
            'intent_version' => '1.0',
            'source_service_id' => '00000000-0000-0000-0000-000000000001',
            'target_service_id' => '00000000-0000-0000-0000-000000000002',
            'user_id' => '00000000-0000-0000-0000-000000000003',
            'idempotency_key' => 'k_' . bin2hex(random_bytes(6)),
            'issued_at' => '2026-05-27T10:00:00Z',
            'payload' => $payload,
        ]);
    }

    private function request(ShareEnvelope $env): Request
    {
        $req = Request::create('/inbound/user-share', 'POST', content: $env->toCanonicalJson());
        $req->attributes->set('source_service_id', '00000000-0000-0000-0000-000000000001');
        $req->attributes->set('peer_slug', 'studendly');
        $req->headers->set('X-Signature', 't=' . time() . ',v1=' . str_repeat('a', 64));
        return $req;
    }
}

class TestPurchasePayload implements SharePayload
{
    public function __construct(public readonly string $orderId) {}
    public static function fromArray(array $raw): self { return new self($raw['order_id']); }
    public function toArray(): array { return ['order_id' => $this->orderId]; }
    public static function intentSlug(): string { return 'test.purchase'; }
    public static function intentVersion(): string { return '1.0'; }
}
