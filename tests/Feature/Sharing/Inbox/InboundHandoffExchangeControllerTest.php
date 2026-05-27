<?php

namespace Tests\Feature\Sharing\Inbox;

use AuthService\Helper\Sharing\Client\HandoffExchangeResult;
use AuthService\Helper\Sharing\Client\HandoffTokenClient;
use AuthService\Helper\Sharing\Inbox\Events\InboundHandoffCompleted;
use AuthService\Helper\Sharing\Inbox\Http\Controllers\InboundHandoffExchangeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Mockery;
use Orchestra\Testbench\TestCase;

class InboundHandoffExchangeControllerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\AuthService\Helper\AuthServiceHelperServiceProvider::class];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_exchange_returns_user_session_token_share_id_next_path(): void
    {
        Event::fake([InboundHandoffCompleted::class]);

        $mockClient = Mockery::mock(HandoffTokenClient::class);
        $mockClient->shouldReceive('exchange')->once()->with('tok_raw_abc')->andReturn(
            new HandoffExchangeResult(
                user: ['uuid' => 'u-uuid', 'name' => 'Jane', 'email' => 'jane@x.com'],
                sessionToken: 'sess_xyz',
                shareId: 'share_99',
                nextPath: '/cases/abc',
            )
        );
        $this->app->instance(HandoffTokenClient::class, $mockClient);

        $req = Request::create('/inbound/handoff/exchange', 'POST', ['token' => 'tok_raw_abc']);
        $resp = $this->app->make(InboundHandoffExchangeController::class)->exchange($req);

        $this->assertEquals(200, $resp->getStatusCode());
        $body = $resp->getData(true);
        $this->assertEquals('u-uuid', $body['user']['uuid']);
        $this->assertEquals('sess_xyz', $body['session_token']);
        $this->assertEquals('share_99', $body['share_id']);
        $this->assertEquals('/cases/abc', $body['next_path']);

        Event::assertDispatched(
            InboundHandoffCompleted::class,
            fn (InboundHandoffCompleted $e) =>
                $e->shareId === 'share_99' && $e->userId === 'u-uuid' && $e->nextPath === '/cases/abc'
        );
    }

    public function test_missing_token_returns_400(): void
    {
        $req = Request::create('/inbound/handoff/exchange', 'POST', []);
        $resp = $this->app->make(InboundHandoffExchangeController::class)->exchange($req);
        $this->assertEquals(400, $resp->getStatusCode());
    }
}
