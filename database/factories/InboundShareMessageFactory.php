<?php

namespace Database\Factories;

use AuthService\Helper\Sharing\Inbox\InboundShareMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class InboundShareMessageFactory extends Factory
{
    protected $model = InboundShareMessage::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'envelope_version' => '1',
            'message_id' => 'msg_' . Str::random(20),
            'correlation_id' => (string) Str::uuid(),
            'intent' => 'service_purchase',
            'intent_version' => '1.0',
            'source_service_id' => (string) Str::uuid(),
            'target_service_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'idempotency_key' => 'studendly:order:' . $this->faker->randomNumber(6),
            'issued_at' => now(),
            'payload' => ['order_id' => 'ord_' . Str::random(10)],
            'signature_header' => 't=' . time() . ',v1=' . str_repeat('a', 64),
            'headers' => ['X-Trust-Key' => 'trust_xxx'],
            'received_at' => now(),
            'processing_status' => 'received',
            'processing_error' => null,
            'dispatched_at' => null,
        ];
    }
}
