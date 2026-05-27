<?php

namespace Tests\Unit\Sharing\Envelope;

use AuthService\Helper\Sharing\Envelope\Exceptions\InvalidEnvelopeException;
use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use PHPUnit\Framework\TestCase;

class ShareEnvelopeTest extends TestCase
{
    public function test_constructs_from_arr_with_all_required_fields(): void
    {
        $env = ShareEnvelope::fromArray($this->validRaw());
        $this->assertEquals('msg_01HZ', $env->messageId);
        $this->assertEquals('share_01HX', $env->correlationId);
        $this->assertEquals('service_purchase', $env->intent);
        $this->assertEquals(['order_id' => 'x'], $env->payload);
    }

    public function test_to_array_round_trips(): void
    {
        $raw = $this->validRaw();
        $env = ShareEnvelope::fromArray($raw);
        $this->assertEquals($raw, $env->toArray());
    }

    public function test_to_canonical_json_is_idempotent(): void
    {
        $env = ShareEnvelope::fromArray($this->validRaw());
        $json = $env->toCanonicalJson();
        $rebuilt = ShareEnvelope::fromArray(json_decode($json, true));
        $this->assertEquals($json, $rebuilt->toCanonicalJson());
    }

    public function test_missing_required_field_throws(): void
    {
        $this->expectException(InvalidEnvelopeException::class);
        $raw = $this->validRaw();
        unset($raw['idempotency_key']);
        ShareEnvelope::fromArray($raw);
    }

    public function test_unknown_envelope_version_throws(): void
    {
        $this->expectException(InvalidEnvelopeException::class);
        $raw = $this->validRaw();
        $raw['envelope_version'] = '99';
        ShareEnvelope::fromArray($raw);
    }

    private function validRaw(): array
    {
        return [
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
        ];
    }
}
