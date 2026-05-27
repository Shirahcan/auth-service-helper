<?php

namespace Tests\Feature\Sharing\Inbox;

use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use AuthService\Helper\Sharing\Inbox\Http\Controllers\InboundShareWebhookController;
use AuthService\Helper\Sharing\Inbox\InboundShareMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;

class InboundShareWebhookPersistTest extends TestCase
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

    public function test_persists_envelope_with_received_status_and_returns_202(): void
    {
        $env = $this->fakeEnv('k-unique-1');
        $req = $this->buildRequest($env, sourceServiceId: $this->sourceId());

        /** @var InboundShareWebhookController $ctrl */
        $ctrl = $this->app->make(InboundShareWebhookController::class);
        $resp = $ctrl->receive($req);

        $this->assertEquals(202, $resp->getStatusCode());
        $body = $resp->getData(true);
        $this->assertEquals($env->messageId, $body['message_id']);
        $this->assertEquals('received', $body['status']);

        $row = InboundShareMessage::query()
            ->where('source_service_id', $this->sourceId())
            ->where('idempotency_key', 'k-unique-1')
            ->first();

        $this->assertNotNull($row);
        $this->assertEquals('received', $row->processing_status);
        $this->assertEquals($env->intent, $row->intent);
        $this->assertEquals($env->payload, $row->payload);
        $this->assertEquals('1', $row->envelope_version);
    }

    public function test_duplicate_idempotency_key_returns_200_already_processed(): void
    {
        $env = $this->fakeEnv('dup-key');

        $first = $this->app->make(InboundShareWebhookController::class)
            ->receive($this->buildRequest($env, $this->sourceId()));
        $this->assertEquals(202, $first->getStatusCode());

        // Replay the same envelope (same source + same idempotency_key)
        $second = $this->app->make(InboundShareWebhookController::class)
            ->receive($this->buildRequest($env, $this->sourceId()));

        $this->assertEquals(200, $second->getStatusCode());
        $body = $second->getData(true);
        $this->assertTrue($body['already_processed']);

        // Exactly one row should have been persisted (UNIQUE blocks the dup)
        $count = InboundShareMessage::where('source_service_id', $this->sourceId())
            ->where('idempotency_key', 'dup-key')
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_same_idempotency_key_from_different_source_is_independent(): void
    {
        $env = $this->fakeEnv('shared-key');

        $r1 = $this->app->make(InboundShareWebhookController::class)
            ->receive($this->buildRequest($env, '00000000-0000-0000-0000-00000000AAAA'));
        $r2 = $this->app->make(InboundShareWebhookController::class)
            ->receive($this->buildRequest($env, '00000000-0000-0000-0000-00000000BBBB'));

        $this->assertEquals(202, $r1->getStatusCode());
        $this->assertEquals(202, $r2->getStatusCode());
        $this->assertEquals(2, InboundShareMessage::where('idempotency_key', 'shared-key')->count());
    }

    public function test_malformed_envelope_returns_400(): void
    {
        $req = Request::create('/inbound/user-share', 'POST', content: '{"not": "an envelope"}');
        $req->attributes->set('source_service_id', $this->sourceId());
        $req->attributes->set('peer_slug', 'studendly');
        $req->headers->set('X-Signature', 't=1,v1=' . str_repeat('a', 64));

        $resp = $this->app->make(InboundShareWebhookController::class)->receive($req);
        $this->assertEquals(400, $resp->getStatusCode());
    }

    private function sourceId(): string
    {
        return '00000000-0000-0000-0000-000000000001';
    }

    private function fakeEnv(string $idempotencyKey): ShareEnvelope
    {
        return ShareEnvelope::fromArray([
            'envelope_version' => '1',
            'message_id' => 'msg_' . bin2hex(random_bytes(8)),
            'correlation_id' => '00000000-0000-0000-0000-000000000010',
            'intent' => 'service_purchase',
            'intent_version' => '1.0',
            'source_service_id' => $this->sourceId(),
            'target_service_id' => '00000000-0000-0000-0000-000000000002',
            'user_id' => '00000000-0000-0000-0000-000000000003',
            'idempotency_key' => $idempotencyKey,
            'issued_at' => '2026-05-27T10:00:00Z',
            'payload' => ['order_id' => 'ord_x'],
        ]);
    }

    private function buildRequest(ShareEnvelope $env, string $sourceServiceId): Request
    {
        $req = Request::create('/inbound/user-share', 'POST', content: $env->toCanonicalJson());
        $req->attributes->set('source_service_id', $sourceServiceId);
        $req->attributes->set('peer_slug', 'studendly');
        $req->headers->set('X-Signature', 't=' . time() . ',v1=' . str_repeat('a', 64));
        $req->headers->set('Content-Type', 'application/json');
        return $req;
    }
}
