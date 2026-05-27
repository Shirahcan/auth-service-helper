<?php

namespace Tests\Feature\Sharing\Inbox;

use AuthService\Helper\Sharing\Envelope\EnvelopeSigner;
use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use AuthService\Helper\Sharing\Inbox\Http\Middleware\VerifyShareEnvelopeSignature;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;

class VerifyShareEnvelopeSignatureTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\AuthService\Helper\AuthServiceHelperServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('authservice-sharing.peers', [
            'studendly' => [
                'source_service_id' => '00000000-0000-0000-0000-000000000001',
                'trust_key' => 'trust_studendly_xxx',
                'current_secret' => 'sec_current',
                'previous_secret' => null,
            ],
        ]);
    }

    public function test_passes_with_valid_trust_key_and_signature(): void
    {
        $env = $this->fakeEnv();
        $body = $env->toCanonicalJson();
        $header = (new EnvelopeSigner('sec_current'))->sign($env);

        $req = Request::create('/inbound/user-share', 'POST', [], [], [], [
            'HTTP_X_TRUST_KEY' => 'trust_studendly_xxx',
            'HTTP_X_SIGNATURE' => $header,
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        $called = false;
        $next = function (Request $r) use (&$called) {
            $called = true;
            $this->assertEquals('00000000-0000-0000-0000-000000000001', $r->attributes->get('source_service_id'));
            $this->assertEquals('studendly', $r->attributes->get('peer_slug'));
            return response('ok', 200);
        };

        $resp = (new VerifyShareEnvelopeSignature())->handle($req, $next);
        $this->assertTrue($called);
        $this->assertEquals(200, $resp->getStatusCode());
    }

    public function test_unknown_trust_key_returns_401(): void
    {
        $req = Request::create('/inbound/user-share', 'POST', [], [], [], [
            'HTTP_X_TRUST_KEY' => 'trust_unknown',
            'HTTP_X_SIGNATURE' => 't=' . time() . ',v1=' . str_repeat('a', 64),
        ], '{}');

        $resp = (new VerifyShareEnvelopeSignature())->handle($req, fn () => response('ok'));
        $this->assertEquals(401, $resp->getStatusCode());
    }

    public function test_missing_trust_key_returns_401(): void
    {
        $req = Request::create('/inbound/user-share', 'POST', [], [], [], [], '{}');
        $resp = (new VerifyShareEnvelopeSignature())->handle($req, fn () => response('ok'));
        $this->assertEquals(401, $resp->getStatusCode());
    }

    public function test_bad_signature_returns_400(): void
    {
        $env = $this->fakeEnv();
        $body = $env->toCanonicalJson();
        $badHeader = (new EnvelopeSigner('WRONG_SECRET'))->sign($env);

        $req = Request::create('/inbound/user-share', 'POST', [], [], [], [
            'HTTP_X_TRUST_KEY' => 'trust_studendly_xxx',
            'HTTP_X_SIGNATURE' => $badHeader,
        ], $body);

        $resp = (new VerifyShareEnvelopeSignature())->handle($req, fn () => response('ok'));
        $this->assertEquals(400, $resp->getStatusCode());
    }

    public function test_previous_secret_accepted_during_rotation(): void
    {
        config()->set('authservice-sharing.peers.studendly.current_secret', 'sec_new');
        config()->set('authservice-sharing.peers.studendly.previous_secret', 'sec_old');

        $env = $this->fakeEnv();
        $body = $env->toCanonicalJson();
        $header = (new EnvelopeSigner('sec_old'))->sign($env);

        $req = Request::create('/inbound/user-share', 'POST', [], [], [], [
            'HTTP_X_TRUST_KEY' => 'trust_studendly_xxx',
            'HTTP_X_SIGNATURE' => $header,
        ], $body);

        $resp = (new VerifyShareEnvelopeSignature())->handle($req, fn () => response('ok', 200));
        $this->assertEquals(200, $resp->getStatusCode());
    }

    private function fakeEnv(): ShareEnvelope
    {
        return ShareEnvelope::fromArray([
            'envelope_version' => '1',
            'message_id' => 'msg_' . bin2hex(random_bytes(8)),
            'correlation_id' => '00000000-0000-0000-0000-000000000010',
            'intent' => 'service_purchase',
            'intent_version' => '1.0',
            'source_service_id' => '00000000-0000-0000-0000-000000000001',
            'target_service_id' => '00000000-0000-0000-0000-000000000002',
            'user_id' => '00000000-0000-0000-0000-000000000003',
            'idempotency_key' => 'k_' . bin2hex(random_bytes(8)),
            'issued_at' => '2026-05-27T10:00:00Z',
            'payload' => ['order_id' => 'x'],
        ]);
    }
}
