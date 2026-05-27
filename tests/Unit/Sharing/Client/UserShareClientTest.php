<?php

namespace Tests\Unit\Sharing\Client;

use AuthService\Helper\AuthServiceHelperServiceProvider;
use AuthService\Helper\Sharing\Client\ShareResult;
use AuthService\Helper\Sharing\Client\UserShareClient;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class UserShareClientTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AuthServiceHelperServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('authservice.auth_service_base_url', 'https://auth.example.com');
        $app['config']->set('authservice.auth_service_api_key', 'sk_test_test_secret');
    }

    public function test_share_user_returns_share_result_on_success(): void
    {
        Http::fake([
            'auth.example.com/api/v1/auth/user-shares' => Http::response([
                'shares' => [[
                    'id' => 'share-1',
                    'user_id' => 'user-1',
                    'target_service_id' => 'target-1',
                    'status' => 'active',
                    'granted_role_ids' => ['client'],
                    'metadata' => [],
                ]],
                'conflicts' => [],
            ]),
        ]);

        $client = new UserShareClient();
        $result = $client->shareUser(
            userId: 'user-1',
            targetServiceId: 'target-1',
            intent: 'service_purchase',
            grantedRoles: ['client'],
        );

        $this->assertInstanceOf(ShareResult::class, $result);
        $this->assertEquals('share-1', $result->id);
        $this->assertEquals('active', $result->status);
        $this->assertNull($result->conflict);
    }

    public function test_share_user_returns_conflict_result_when_collision(): void
    {
        Http::fake([
            'auth.example.com/api/v1/auth/user-shares' => Http::response([
                'shares' => [],
                'conflicts' => [[
                    'id' => 'conflict-1',
                    'source_user_id' => 'user-1',
                    'target_existing_user_id' => 'user-99',
                    'status' => 'pending',
                ]],
            ], 409),
        ]);

        $client = new UserShareClient();
        $result = $client->shareUser('user-1', 'target-1');

        $this->assertNotNull($result->conflict);
        $this->assertEquals('conflict-1', $result->conflict->id);
        $this->assertEquals('user-99', $result->conflict->targetExistingUserId);
    }

    public function test_revoke_sends_delete(): void
    {
        Http::fake(['auth.example.com/*' => Http::response([], 200)]);

        $client = new UserShareClient();
        $client->revoke('share-1', reason: 'admission_withdrawn');

        Http::assertSent(fn ($req) =>
            $req->method() === 'DELETE'
            && str_contains($req->url(), '/auth/user-shares/share-1')
        );
    }

    public function test_resolve_collision_posts_strategy(): void
    {
        Http::fake([
            'auth.example.com/api/v1/auth/user-shares/conflicts/*/resolve' =>
                Http::response(['status' => 'resolved_alias'], 200),
        ]);

        $client = new UserShareClient();
        $result = $client->resolveCollision('conflict-1', 'link_alias');

        $this->assertEquals('resolved_alias', $result['status']);
        Http::assertSent(fn ($req) =>
            $req->method() === 'POST'
            && str_contains($req->url(), '/auth/user-shares/conflicts/conflict-1/resolve')
            && $req['strategy'] === 'link_alias'
        );
    }
}
