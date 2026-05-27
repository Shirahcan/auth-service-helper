<?php

namespace Tests\Feature\Sharing\Inbox;

use AuthService\Helper\Sharing\Client\HandoffExchangeResult;
use AuthService\Helper\Sharing\Client\HandoffTokenClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Mockery;
use Orchestra\Testbench\TestCase;

class RoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [\AuthService\Helper\AuthServiceHelperServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('authservice.sharing.internal_token', 'internal_xxx');
        $app['config']->set('authservice.sharing.peers', []);
    }

    public function test_inbound_user_share_route_is_registered(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/v1/inbound/user-share' && in_array('POST', $r->methods(), true)
        );
        $this->assertNotNull($route, 'POST /api/v1/inbound/user-share not registered');

        $middleware = $route->gatherMiddleware();
        $this->assertTrue(
            collect($middleware)->contains(fn ($m) => str_contains($m, 'VerifyShareEnvelopeSignature'))
            || in_array('share-envelope.verify', $middleware, true),
            'user-share route missing signature middleware'
        );
    }

    public function test_inbound_handoff_exchange_route_is_registered(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/v1/inbound/handoff/exchange' && in_array('POST', $r->methods(), true)
        );
        $this->assertNotNull($route, 'POST /api/v1/inbound/handoff/exchange not registered');

        $middleware = $route->gatherMiddleware();
        $this->assertTrue(
            collect($middleware)->contains(fn ($m) => str_contains($m, 'VerifyInternalToken'))
            || in_array('share-helper.internal', $middleware, true),
            'handoff/exchange route missing internal-token middleware'
        );
    }

    public function test_internal_middleware_rejects_missing_token(): void
    {
        $resp = $this->postJson('/api/v1/inbound/handoff/exchange', ['token' => 'tok']);
        $this->assertEquals(401, $resp->getStatusCode());
    }

    public function test_internal_middleware_accepts_matching_token(): void
    {
        $mock = Mockery::mock(HandoffTokenClient::class);
        $mock->shouldReceive('exchange')->andReturn(
            new HandoffExchangeResult(
                user: ['uuid' => 'u-1', 'name' => 'X'],
                sessionToken: 's',
                shareId: 'sh',
                nextPath: '/',
            )
        );
        $this->app->instance(HandoffTokenClient::class, $mock);

        $resp = $this->postJson(
            '/api/v1/inbound/handoff/exchange',
            ['token' => 'tok'],
            ['X-Internal-Token' => 'internal_xxx'],
        );
        $this->assertEquals(200, $resp->getStatusCode());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_config_publish_tag_is_registered(): void
    {
        $groups = \Illuminate\Support\ServiceProvider::publishableGroups();
        $this->assertContains('auth-service-helper-sharing-config', $groups);
    }
}
