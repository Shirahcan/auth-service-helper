<?php

namespace Database\Factories;

use AuthService\Helper\Sharing\Outbox\OutboundShareMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OutboundShareMessageFactory extends Factory
{
    protected $model = OutboundShareMessage::class;

    public function definition(): array
    {
        $shareId = (string) Str::uuid();
        $idempotencyKey = 'idem:'.Str::random(16);

        return [
            'id' => (string) Str::uuid(),
            'share_id' => $shareId,
            'target_service_id' => (string) Str::uuid(),
            'peer_slug' => 'portify',
            'intent' => 'service_purchase',
            'intent_version' => '1.0',
            'idempotency_key' => $idempotencyKey,
            'envelope_json' => [
                'envelope_version' => '1',
                'message_id' => 'msg_'.Str::random(12),
                'correlation_id' => $shareId,
                'intent' => 'service_purchase',
                'intent_version' => '1.0',
                'source_service_id' => (string) Str::uuid(),
                'target_service_id' => (string) Str::uuid(),
                'user_id' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'issued_at' => now()->toIso8601String(),
                'payload' => ['order_id' => 'order:'.Str::random(6)],
            ],
            'signature_header' => null,
            'status' => OutboundShareMessage::STATUS_QUEUED,
            'attempts' => 0,
            'last_attempt_at' => null,
            'next_retry_at' => null,
            'delivered_at' => null,
            'dead_lettered_at' => null,
            'last_error' => null,
            'last_response_status' => null,
        ];
    }
}
