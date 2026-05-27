<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Inbound webhook path
    |--------------------------------------------------------------------------
    | The path on which the InboundShareWebhookController is mounted. Change
    | only if you need to integrate with an existing routing convention.
    */
    'webhook_path' => '/api/v1/inbound/user-share',

    /*
    |--------------------------------------------------------------------------
    | Handoff exchange path
    |--------------------------------------------------------------------------
    | The path on which InboundHandoffExchangeController is mounted. The Next
    | helper's /auth/handoff route POSTs here.
    */
    'handoff_exchange_path' => '/api/v1/inbound/handoff/exchange',

    /*
    |--------------------------------------------------------------------------
    | Internal token (shared between Next backend and PHP backend)
    |--------------------------------------------------------------------------
    | Must be set per environment via SHARING_INTERNAL_TOKEN env var. The Next
    | helper sends this in X-Internal-Token when calling the handoff exchange.
    */
    'internal_token' => env('SHARING_INTERNAL_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Replay window (seconds)
    |--------------------------------------------------------------------------
    | Allowable clock skew between source and destination for X-Signature
    | timestamps. Spec default: 300s (±5min).
    */
    'replay_window_seconds' => 300,

    /*
    |--------------------------------------------------------------------------
    | Idempotency retention (days)
    |--------------------------------------------------------------------------
    | How long inbound_share_messages rows are retained for idempotency
    | de-duplication. Cleanup is operator-driven (see Phase H4).
    */
    'idempotency_retention_days' => 30,

    /*
    |--------------------------------------------------------------------------
    | Peer registry
    |--------------------------------------------------------------------------
    | Each entry is a trusted source product whose webhooks this destination
    | accepts. The middleware matches by `trust_key`. Add one per consumer
    | product. previous_secret enables zero-downtime HMAC rotation.
    |
    | 'studendly' => [
    |     'source_service_id' => env('STUDENDLY_SERVICE_ID'),
    |     'trust_key'         => env('STUDENDLY_TRUST_KEY'),
    |     'current_secret'    => env('STUDENDLY_HMAC_SECRET'),
    |     'previous_secret'   => env('STUDENDLY_HMAC_SECRET_PREV'),
    | ],
    */
    'peers' => [],
];
