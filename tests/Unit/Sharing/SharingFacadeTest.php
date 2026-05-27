<?php

namespace Tests\Unit\Sharing;

use AuthService\Helper\AuthServiceHelperServiceProvider;
use AuthService\Helper\Sharing\Exceptions\UserShareCollisionException;
use AuthService\Helper\Sharing\Facades\Sharing;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class SharingFacadeTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AuthServiceHelperServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('authservice.auth_service_base_url', 'https://auth.example.com');
        $app['config']->set('authservice.auth_service_api_key', 'sk_test');
    }

    public function test_share_user_via_facade(): void
    {
        Http::fake([
            'auth.example.com/api/v1/auth/user-shares' => Http::response([
                'shares' => [[
                    'id' => 's-1', 'user_id' => 'u-1', 'target_service_id' => 't-1',
                    'status' => 'active', 'granted_role_ids' => [], 'metadata' => [],
                ]],
                'conflicts' => [],
            ]),
        ]);

        $result = Sharing::shareUser(
            userId: 'u-1',
            targetService: '00000000-0000-0000-0000-000000000001',
            intent: 'service_purchase',
        );

        $this->assertEquals('s-1', $result->id);
    }

    public function test_share_user_strict_mode_throws_on_conflict(): void
    {
        Http::fake([
            'auth.example.com/*' => Http::response([
                'shares' => [],
                'conflicts' => [[
                    'id' => 'c-1', 'source_user_id' => 'u-1',
                    'target_existing_user_id' => 'u-99', 'status' => 'pending',
                ]],
            ], 409),
        ]);

        $this->expectException(UserShareCollisionException::class);
        Sharing::shareUser(
            userId: 'u-1',
            targetService: '00000000-0000-0000-0000-000000000001',
            strictOnConflict: true,
        );
    }

    public function test_send_payload_throws_logic_exception_until_e2(): void
    {
        $this->expectException(\LogicException::class);
        Sharing::sendPayload(
            'share-1',
            'service_purchase',
            new \AuthService\Helper\Sharing\Intents\Builtin\ServicePurchasePayload(
                orderId: 'o-1', serviceSlug: 'x', amountCents: 100,
                currency: 'CAD', purchasedAt: '2026-05-27T10:00:00Z', items: [],
            ),
            'idem-1',
        );
    }
}
