<?php

namespace Tests\Unit\Sharing\Client;

use AuthService\Helper\AuthServiceHelperServiceProvider;
use AuthService\Helper\Sharing\Client\HandoffTokenClient;
use AuthService\Helper\Sharing\Exceptions\HandoffTokenInvalidException;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class HandoffTokenClientTest extends TestCase
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

    public function test_mint_returns_redirect_url(): void
    {
        Http::fake([
            'auth.example.com/api/v1/auth/handoff-tokens' => Http::response([
                'token' => 'abc',
                'redirect_url' => 'https://portify.app/auth/handoff?token=abc',
                'expires_at' => '2026-05-27T10:01:00Z',
            ]),
        ]);

        $client = new HandoffTokenClient();
        $result = $client->mint('share-1', '/cases/abc');

        $this->assertEquals('abc', $result->token);
        $this->assertStringStartsWith('https://portify.app', $result->redirectUrl);
    }

    public function test_exchange_returns_result_on_success(): void
    {
        Http::fake([
            'auth.example.com/api/v1/auth/handoff-tokens/abc/exchange' => Http::response([
                'user' => ['uuid' => 'u-1', 'name' => 'Alice', 'email' => 'alice@x.com'],
                'session_token' => 'sess-1',
                'share_id' => 'share-1',
                'next_path' => '/cases/abc',
            ]),
        ]);

        $client = new HandoffTokenClient();
        $result = $client->exchange('abc');

        $this->assertEquals('sess-1', $result->sessionToken);
        $this->assertEquals('share-1', $result->shareId);
    }

    public function test_exchange_throws_expired_on_410_token_expired(): void
    {
        Http::fake([
            'auth.example.com/*' => Http::response(['error' => 'token_expired'], 410),
        ]);

        $client = new HandoffTokenClient();
        try {
            $client->exchange('abc');
            $this->fail('Should have thrown HandoffTokenInvalidException');
        } catch (HandoffTokenInvalidException $e) {
            $this->assertEquals(HandoffTokenInvalidException::REASON_EXPIRED, $e->reason);
        }
    }

    public function test_exchange_throws_consumed_on_410_token_consumed(): void
    {
        Http::fake([
            'auth.example.com/*' => Http::response(['error' => 'token_consumed'], 410),
        ]);

        $client = new HandoffTokenClient();
        try {
            $client->exchange('abc');
            $this->fail('Should have thrown');
        } catch (HandoffTokenInvalidException $e) {
            $this->assertEquals(HandoffTokenInvalidException::REASON_CONSUMED, $e->reason);
        }
    }

    public function test_exchange_throws_wrong_target_on_403(): void
    {
        Http::fake(['auth.example.com/*' => Http::response(['error' => 'wrong_target'], 403)]);

        $client = new HandoffTokenClient();
        try {
            $client->exchange('abc');
            $this->fail('Should have thrown');
        } catch (HandoffTokenInvalidException $e) {
            $this->assertEquals(HandoffTokenInvalidException::REASON_WRONG_TARGET, $e->reason);
        }
    }
}
