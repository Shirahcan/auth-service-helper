<?php

namespace Database\Factories;

use AuthService\Helper\Sharing\Prep\PrepResource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PrepResourceFactory extends Factory
{
    protected $model = PrepResource::class;

    public function definition(): array
    {
        return [
            'id'                 => (string) Str::uuid(),
            'source_service_id'  => (string) Str::uuid(),
            'idempotency_key'    => 'studendly:checkout:' . Str::random(10) . ':agreement:visa-rep',
            'intent'             => 'agreement_sign',
            'intent_version'     => '1.0',
            'source_resource'    => ['type' => 'checkout', 'id' => 'ckt_' . Str::random(6)],
            'student_data'       => ['external_id' => (string) Str::uuid(), 'email' => 'jane@example.test', 'name' => 'Jane Student'],
            'payload'            => ['agreement_slug' => 'visa-rep-agreement'],
            'signed_data'        => null,
            'return_to'          => 'https://studendly.test/checkout/done',
            'status'             => PrepResource::STATUS_PREPARED,
            'prepared_at'        => now(),
            'signed_at'          => null,
            'promoted_at'        => null,
            'expires_at'         => now()->addHours(24),
            'permanent_resource_id' => null,
        ];
    }
}
