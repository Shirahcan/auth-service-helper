<?php

namespace Tests\Unit\Sharing\Envelope;

use AuthService\Helper\Sharing\Envelope\EnvelopeSigner;
use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use PHPUnit\Framework\TestCase;

class EnvelopeSignerTest extends TestCase
{
    public function test_sign_produces_header_value_with_t_and_v1(): void
    {
        $signer = new EnvelopeSigner('shh');
        $header = $signer->sign($this->envelope(), at: 1700000000);
        $this->assertMatchesRegularExpression(
            '/^t=1700000000,v1=[a-f0-9]{64}$/',
            $header
        );
    }

    public function test_sign_is_deterministic(): void
    {
        $signer = new EnvelopeSigner('shh');
        $env = $this->envelope();
        $this->assertEquals(
            $signer->sign($env, at: 1700000000),
            $signer->sign($env, at: 1700000000),
        );
    }

    public function test_sign_changes_when_secret_changes(): void
    {
        $env = $this->envelope();
        $this->assertNotEquals(
            (new EnvelopeSigner('a'))->sign($env, at: 1700000000),
            (new EnvelopeSigner('b'))->sign($env, at: 1700000000),
        );
    }

    private function envelope(): ShareEnvelope
    {
        return ShareEnvelope::fromArray([
            'envelope_version' => '1',
            'message_id' => 'msg_01HZ',
            'correlation_id' => 'share_01HX',
            'intent' => 'service_purchase',
            'intent_version' => '1.0',
            'source_service_id' => '00000000-0000-0000-0000-000000000001',
            'target_service_id' => '00000000-0000-0000-0000-000000000002',
            'user_id' => '00000000-0000-0000-0000-000000000003',
            'idempotency_key' => 'studendly:order:88421',
            'issued_at' => '2026-05-27T10:00:00Z',
            'payload' => ['order_id' => 'x'],
        ]);
    }
}
