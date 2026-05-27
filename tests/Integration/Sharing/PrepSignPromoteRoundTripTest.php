<?php

namespace Tests\Integration\Sharing;

use AuthService\Helper\AuthServiceHelperServiceProvider;
use AuthService\Helper\Sharing\Client\UserShareClient;
use AuthService\Helper\Sharing\Facades\Sharing;
use AuthService\Helper\Sharing\Prep\Events\PrepResourceCreated;
use AuthService\Helper\Sharing\Prep\Events\PrepResourcePromoted;
use AuthService\Helper\Sharing\Prep\Events\PrepResourceSigned;
use AuthService\Helper\Sharing\Prep\Http\Controllers\EmbedController;
use AuthService\Helper\Sharing\Prep\Http\Controllers\PrepareController;
use AuthService\Helper\Sharing\Prep\Http\Controllers\PromoteController;
use AuthService\Helper\Sharing\Prep\PrepResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery;
use Orchestra\Testbench\TestCase;

class PrepSignPromoteRoundTripTest extends TestCase
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
        $app['config']->set('authservice.sharing.source_service_id', '11111111-1111-1111-1111-111111111111');
        $app['config']->set('authservice.sharing.peers.portify', [
            'webhook_url'       => 'http://localhost/api/v1/inbound/user-share',
            'prep_base_url'     => 'http://localhost/api/v1/sharing/prep',
            'signing_secret'    => 'shared-rt-secret',
            'trust_key'         => 'trust-rt-key',
            'target_service_id' => '22222222-2222-2222-2222-222222222222',
            'source_service_id' => '11111111-1111-1111-1111-111111111111',
            'current_secret'    => 'shared-rt-secret',
        ]);
        $app['config']->set('authservice.sharing.prep.embed_base_url', 'http://localhost');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_full_round_trip_prepare_sign_shareuser_promote(): void
    {
        Event::fake([PrepResourceCreated::class, PrepResourceSigned::class, PrepResourcePromoted::class]);

        $self = $this;

        // ── 1. PREPARE: bridge outbound HTTP into in-process controller ──
        Http::fake([
            'localhost/api/v1/sharing/prep/prepare' => function ($req) use ($self) {
                $r = $self->routeIntoController($req, '/api/v1/sharing/prep/prepare', PrepareController::class, 'prepare');
                return Http::response($r->getContent(), $r->getStatusCode());
            },
        ]);

        $prep = Sharing::prepare(
            peerSlug: 'portify',
            intentSlug: 'agreement_sign',
            idempotencyKey: 'studendly:checkout:ckt_1:agreement:visa-rep',
            sourceResource: ['type' => 'checkout', 'id' => 'ckt_1'],
            studentData:    ['external_id' => 'stu_1', 'email' => 'jane@x.com', 'name' => 'Jane Student'],
            payload:        ['agreement_slug' => 'visa-rep-agreement'],
            returnTo:       'http://studendly.test/done',
        );
        $this->assertNotEmpty($prep->prepId);
        $this->assertStringContainsString('/sharing/embed/agreement_sign/', $prep->embedUrl);
        Event::assertDispatched(PrepResourceCreated::class);

        // ── 2. STUDENT SIGNS IN IFRAME (simulated) ──────────────────────
        $submitReq = Request::create(
            "/sharing/embed/agreement_sign/{$prep->prepId}/submit",
            'POST',
            content: json_encode(['signature' => 'Jane Student', 'agreed_at' => '2026-05-27T10:00:00Z']),
        );
        $submitReq->headers->set('Content-Type', 'application/json');
        $submitResp = app(EmbedController::class)->submit($submitReq, 'agreement_sign', $prep->prepId);
        $this->assertEquals(200, $submitResp->getStatusCode());
        Event::assertDispatched(PrepResourceSigned::class);

        $row = PrepResource::find($prep->prepId);
        $this->assertEquals('signed', $row->status);

        // ── 3. PAYMENT CONFIRMS → SHAREUSER ON AUTH-SERVICE ─────────────
        // (Mock auth-service share lookup since auth-service isn't booted in this test)
        $shareMock = Mockery::mock(UserShareClient::class);
        $shareMock->shouldReceive('get')->andReturn(new \AuthService\Helper\Sharing\Client\ShareResult(
            id: 'sh-1',
            userId: 'u-1',
            targetServiceId: '22222222-2222-2222-2222-222222222222',
            status: 'active',
            grantedRoleIds: [],
            metadata: [],
        ));
        $this->app->instance(UserShareClient::class, $shareMock);

        // ── 4. PROMOTE ──────────────────────────────────────────────────
        Http::fake([
            'localhost/api/v1/sharing/prep/' . $prep->prepId . '/promote' => function ($req) use ($self, $prep) {
                $r = $self->routeIntoController($req, "/api/v1/sharing/prep/{$prep->prepId}/promote", PromoteController::class, 'promote', $prep->prepId);
                return Http::response($r->getContent(), $r->getStatusCode());
            },
        ]);

        $promoted = Sharing::promote(
            peerSlug: 'portify',
            prepId: $prep->prepId,
            shareId: 'sh-1',
            triggerProof: ['payment_id' => 'pay-1', 'amount_cents' => 40000],
            idempotencyKey: 'studendly:checkout:ckt_1:agreement:visa-rep',
        );

        $this->assertNotEmpty($promoted->permanentResourceId);
        $this->assertEquals('pending_activation', $promoted->state);
        Event::assertDispatched(PrepResourcePromoted::class);

        $row->refresh();
        $this->assertEquals('promoted', $row->status);
        $this->assertEquals($promoted->permanentResourceId, $row->permanent_resource_id);

        // ── 5. IDEMPOTENT PROMOTE RE-CALL ───────────────────────────────
        $reTry = Sharing::promote(
            peerSlug: 'portify',
            prepId: $prep->prepId,
            shareId: 'sh-1',
            triggerProof: ['payment_id' => 'pay-1'],
            idempotencyKey: 'studendly:checkout:ckt_1:agreement:visa-rep',
        );
        $this->assertEquals($promoted->permanentResourceId, $reTry->permanentResourceId);
    }

    /**
     * Bridge an outbound Http::fake'd request into the in-process controller.
     */
    public function routeIntoController($request, string $uri, string $controller, string $method, ?string $prepId = null)
    {
        $headers = [];
        foreach ($request->headers() as $name => $values) {
            $headers['HTTP_' . strtoupper(str_replace('-', '_', $name))] = is_array($values) ? ($values[0] ?? '') : (string) $values;
        }
        $headers['CONTENT_TYPE'] = 'application/json';

        $req = Request::create(uri: $uri, method: 'POST', server: $headers, content: $request->body());
        $req->attributes->set('source_service_id', '11111111-1111-1111-1111-111111111111');
        $req->attributes->set('peer_slug', 'studendly');

        return $prepId !== null
            ? app($controller)->{$method}($req, $prepId)
            : app($controller)->{$method}($req);
    }
}
