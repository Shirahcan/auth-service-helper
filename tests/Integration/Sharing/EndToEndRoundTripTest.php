<?php

namespace Tests\Integration\Sharing;

use AuthService\Helper\AuthServiceHelperServiceProvider;
use AuthService\Helper\Sharing\Facades\Sharing;
use AuthService\Helper\Sharing\Inbox\Events\InboundHandoffCompleted;
use AuthService\Helper\Sharing\Inbox\Events\InboundShareReceived;
use AuthService\Helper\Sharing\Inbox\Http\Controllers\InboundHandoffExchangeController;
use AuthService\Helper\Sharing\Inbox\Http\Controllers\InboundShareWebhookController;
use AuthService\Helper\Sharing\Intents\Builtin\ServicePurchasePayload;
use AuthService\Helper\Sharing\Outbox\Jobs\DispatchOutboundShareJob;
use AuthService\Helper\Sharing\Outbox\OutboundShareMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use Tests\Integration\Sharing\Fixtures\TestServicePurchaseHandler;

class EndToEndRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [AuthServiceHelperServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    protected function defineEnvironment($app): void
    {
        // Single app plays both roles. One peer entry carries BOTH source-side
        // (webhook_url, signing_secret, trust_key) and destination-side
        // (current_secret, source_service_id) fields. signing_secret ==
        // current_secret so the signed envelope verifies against itself.
        $app['config']->set('authservice.sharing.source_service_id', '11111111-1111-1111-1111-111111111111');
        $app['config']->set('authservice.sharing.internal_token', 'internal-rt');
        $app['config']->set('authservice.sharing.peers.portify', [
            'webhook_url' => 'http://localhost/api/v1/inbound/user-share',
            'signing_secret' => 'shared-rt-secret',
            'trust_key' => 'trust-rt-key',
            'target_service_id' => '22222222-2222-2222-2222-222222222222',
            'source_service_id' => '11111111-1111-1111-1111-111111111111',
            'current_secret' => 'shared-rt-secret',
            'previous_secret' => null,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        TestServicePurchaseHandler::reset();
    }

    public function test_payload_round_trip_dispatches_inbound_event_on_destination(): void
    {
        Event::listen(InboundShareReceived::class, [TestServicePurchaseHandler::class, 'handle']);

        $shareId = '33333333-3333-3333-3333-333333333333';
        $userId = '44444444-4444-4444-4444-444444444444';

        $outbound = Sharing::sendPayload(
            shareId: $shareId,
            intent: 'service_purchase',
            payload: new ServicePurchasePayload(
                orderId: 'studendly:order:88421',
                serviceSlug: 'study_visa',
                amountCents: 40000,
                currency: 'CAD',
                purchasedAt: '2026-05-27T10:00:00Z',
                items: [],
            ),
            idempotencyKey: 'studendly:order:88421',
            peerSlug: 'portify',
            targetServiceId: '22222222-2222-2222-2222-222222222222',
            userId: $userId,
            sourceServiceId: '11111111-1111-1111-1111-111111111111',
        );

        $this->assertInstanceOf(OutboundShareMessage::class, $outbound);
        $this->assertSame('queued', $outbound->status);

        // Bridge the source's outbound HTTP POST into the destination's
        // controller in-process. Skips Laravel routing + middleware (covered
        // by RoutingTest); the goal here is to prove the source/dest
        // round-trip semantics, not Laravel's HTTP plumbing.
        Http::fake([
            'localhost/api/v1/inbound/user-share' => function ($request) {
                $sig  = $request->header('X-Signature');
                $trust = $request->header('X-Trust-Key');
                $idem = $request->header('X-Idempotency-Key');

                $req = Request::create(
                    uri: '/api/v1/inbound/user-share',
                    method: 'POST',
                    server: [
                        'HTTP_X_SIGNATURE' => is_array($sig) ? ($sig[0] ?? '') : (string) $sig,
                        'HTTP_X_TRUST_KEY' => is_array($trust) ? ($trust[0] ?? '') : (string) $trust,
                        'HTTP_X_IDEMPOTENCY_KEY' => is_array($idem) ? ($idem[0] ?? '') : (string) $idem,
                        'CONTENT_TYPE' => 'application/json',
                    ],
                    content: $request->body(),
                );

                // Simulate the upstream VerifyShareEnvelopeSignature middleware
                // (RoutingTest covers it independently). Stamping these
                // attributes is what the middleware would do on signature pass.
                $req->attributes->set('source_service_id', '11111111-1111-1111-1111-111111111111');
                $req->attributes->set('peer_slug', 'portify');

                $controller = app(InboundShareWebhookController::class);
                $resp = $controller->receive($req);

                return Http::response($resp->getContent(), $resp->getStatusCode());
            },
        ]);

        (new DispatchOutboundShareJob($outbound->id))->handle();

        $this->assertCount(1, TestServicePurchaseHandler::$received);
        $event = TestServicePurchaseHandler::$received[0];
        $this->assertSame('service_purchase', $event->intent);
        $this->assertSame($userId, $event->userId);
        $this->assertInstanceOf(ServicePurchasePayload::class, $event->payload);
        $this->assertSame('studendly:order:88421', $event->payload->orderId);
        $this->assertSame(40000, $event->payload->amountCents);

        $this->assertDatabaseHas('outbound_share_messages', [
            'id' => $outbound->id,
            'status' => 'delivered',
        ]);
    }

    public function test_handoff_round_trip_fires_inbound_handoff_completed_event(): void
    {
        Event::fake([InboundHandoffCompleted::class]);

        $shareId = '55555555-5555-5555-5555-555555555555';
        $token = 'tok_' . str_repeat('a', 40);
        $userId = '66666666-6666-6666-6666-666666666666';

        Http::fake([
            'auth.example.com/api/v1/auth/handoff-tokens' => Http::response([
                'token' => $token,
                'redirect_url' => 'https://destination.test/auth/handoff?token=' . $token,
                'expires_at' => now()->addMinute()->toIso8601String(),
            ], 200),
            'auth.example.com/api/v1/auth/handoff-tokens/' . $token . '/exchange' => Http::response([
                'user' => ['uuid' => $userId, 'name' => 'Jane Student', 'email' => 'jane@example.test'],
                'session_token' => 'sess_' . str_repeat('b', 40),
                'share_id' => $shareId,
                'next_path' => '/cases/abc',
            ], 200),
        ]);

        config()->set('authservice.auth_service_base_url', 'https://auth.example.com');
        config()->set('authservice.auth_service_api_key', 'svc-key');

        $minted = Sharing::mintHandoffToken(shareId: $shareId, nextPath: '/cases/abc');
        $this->assertStringContainsString($token, $minted->redirectUrl);

        // Destination side: call the controller directly. RoutingTest covers
        // the HTTP routing + internal-token middleware separately; this test
        // proves the controller → HandoffTokenClient → event round-trip.
        $req = Request::create('/api/v1/inbound/handoff/exchange', 'POST', ['token' => $token]);
        $controller = app(InboundHandoffExchangeController::class);
        $response = $controller->exchange($req);

        $this->assertEquals(200, $response->getStatusCode());
        $body = $response->getData(true);
        $this->assertEquals($userId, $body['user']['uuid']);
        $this->assertEquals($shareId, $body['share_id']);

        Event::assertDispatched(InboundHandoffCompleted::class, function ($e) use ($shareId, $userId) {
            return $e->shareId === $shareId && $e->userId === $userId;
        });
    }
}
