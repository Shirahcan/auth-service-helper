# Consumer migration guide — adding Sharing to an existing product

This guide walks a product team integrating the cross-product Sharing protocol for the first time. Follow it from top to bottom; each section is independently verifiable.

## 0. Prerequisites

- `shirahcan/auth-service-helper` v1.3.0+ installed
- `@benbraide/auth-service-nextjs` v1.1.0+ installed in the destination Next app (if your product is a destination)
- An auth-service instance reachable at `AUTH_SERVICE_BASE_URL`
- A registered service entry in auth-service for this product (you'll need its `service_id` UUID + a service API key)

## 1. Publish the sharing config

```bash
php artisan vendor:publish --tag=auth-service-helper-sharing-config
```

Edit `config/authservice-sharing.php` and define your peers. Minimum per peer (one entry serves both source AND destination roles, since this product may both send to + receive from any given peer):

```php
'peers' => [
    'portify' => [
        // ─── outbound (this product → portify) ────────────────────────
        'webhook_url'       => env('PORTIFY_WEBHOOK_URL'),         // https://portify.app/api/v1/inbound/user-share
        'signing_secret'    => env('PORTIFY_HMAC_SECRET'),         // we sign with this
        'trust_key'         => env('PORTIFY_TRUST_KEY'),           // portify identifies us by this
        'target_service_id' => env('PORTIFY_SERVICE_ID'),

        // ─── inbound (portify → this product) ─────────────────────────
        'source_service_id' => env('PORTIFY_SERVICE_ID'),          // verify the envelope came from portify
        'current_secret'    => env('PORTIFY_HMAC_SECRET_INBOUND'), // verify portify's signature
        'previous_secret'   => env('PORTIFY_HMAC_SECRET_INBOUND_PREV'),
    ],
],
```

`source_service_id` is the *peer's* service ID; the `target_service_id` on the same entry is also the peer's. They're the same UUID viewed from two angles.

Set the top-level keys too:

```php
'source_service_id' => env('SHARING_SOURCE_SERVICE_ID'), // OUR own service ID
'internal_token'    => env('SHARING_INTERNAL_TOKEN'),    // shared between this Next + this PHP
```

## 2. Add the helper service provider routes to your routes file (optional)

The helper auto-mounts:
- `POST /api/v1/inbound/user-share` (HMAC-protected webhook)
- `POST /api/v1/inbound/handoff/exchange` (internal-token-protected exchange)

Both fire automatically from `SharingServiceProvider::boot()`. No changes to `routes/api.php` required unless you want to override paths via `authservice-sharing.webhook_path` / `handoff_exchange_path` config.

Run migrations:

```bash
php artisan migrate
```

This creates `inbound_share_messages` + `outbound_share_messages` tables.

## 3. Listen for inbound payloads (destination side)

Register one or more listeners in any `EventServiceProvider`:

```php
use AuthService\Helper\Sharing\Inbox\Events\InboundShareReceived;

protected $listen = [
    InboundShareReceived::class => [
        \App\Listeners\Sharing\HandleServicePurchase::class,
        \App\Listeners\Sharing\HandleStatusUpdate::class,
        // etc.
    ],
];
```

A listener:

```php
class HandleServicePurchase
{
    public function handle(InboundShareReceived $event): void
    {
        if ($event->intent !== 'service_purchase') return;

        /** @var \AuthService\Helper\Sharing\Intents\Builtin\ServicePurchasePayload $p */
        $p = $event->payload;
        Order::firstOrCreate(
            ['idempotency_key' => $p->orderId],
            [
                'user_id'      => $event->userId,
                'amount_cents' => $p->amountCents,
                'currency'     => $p->currency,
                'service_slug' => $p->serviceSlug,
                'purchased_at' => $p->purchasedAt,
            ],
        );
    }
}
```

The webhook controller has already done schema validation + idempotency; your listener just maps the typed payload onto your domain.

## 4. Send outbound payloads (source side)

```php
use AuthService\Helper\Sharing\Facades\Sharing;
use AuthService\Helper\Sharing\Intents\Builtin\ServicePurchasePayload;

// somewhere in your purchase flow:
$share = Sharing::shareUser(
    userId: $user->id,
    targetService: 'portify',
    intent: 'service_purchase',
);

Sharing::sendPayload(
    shareId: $share->id,
    intent: 'service_purchase',
    payload: new ServicePurchasePayload(
        orderId:      "studendly:order:{$order->id}",
        serviceSlug:  $order->service->slug,
        amountCents:  $order->amount_cents,
        currency:     $order->currency,
        purchasedAt:  $order->paid_at->toIso8601String(),
        items:        $order->items->map(fn($i) => $i->toShareDto())->all(),
    ),
    idempotencyKey: "studendly:order:{$order->id}",
);
```

Make sure your queue worker is running: `php artisan queue:work` (helper jobs use Laravel's default queue connection).

## 5. Cross-product redirect (source → destination, seamless login)

```php
$mint = Sharing::mintHandoffToken(shareId: $share->id, nextPath: '/cases/abc');
return redirect($mint->redirectUrl);
```

## 6. Destination Next app wiring

Install `@benbraide/auth-service-nextjs`, then:

```ts
// src/app/auth/handoff/route.ts
import { createHandoffRouteHandler } from '@benbraide/auth-service-nextjs/sharing';
export const dynamic = 'force-dynamic';
export const GET = createHandoffRouteHandler({
  backendUrl:    process.env.NEXT_PUBLIC_BACKEND_URL!,
  serviceApiKey: process.env.NEXT_SERVICE_API_KEY!,
  internalToken: process.env.NEXT_INTERNAL_TOKEN!,
});
```

```tsx
// src/app/auth/handoff/landing/page.tsx
'use client';
import { HandoffLanding } from '@benbraide/auth-service-nextjs/sharing';
export default function Page() { return <HandoffLanding />; }
```

```tsx
// src/app/layout.tsx
import { HandoffArrivalToast } from '@benbraide/auth-service-nextjs/components';
// add <HandoffArrivalToast /> somewhere always-mounted
```

## 7. Smoke-test the round-trip

1. Trigger a payload from your source flow
2. Watch the source: `SELECT id, status, attempts FROM outbound_share_messages ORDER BY created_at DESC LIMIT 5;` — should advance `queued → delivered`
3. Watch the destination: `SELECT id, processing_status, intent FROM inbound_share_messages ORDER BY received_at DESC LIMIT 5;` — should land as `dispatched`
4. Watch your listener side-effects (orders created, etc.)

## 8. Ops surfaces

- `Sharing::getDeliveryStatus($shareId)` — per-share counts + last timestamps
- `Sharing::listFailed()` — `dead_lettered` + `failed_permanent` for triage
- `Sharing::redeliver($outboundMessageId)` — re-queue a DLQ row (resets attempts to 0)
- `php artisan sharing:purge-delivered --before=YYYY-MM-DD` — housekeeping

## 9. Rotating HMAC secrets without downtime

1. Set both `current_secret` and `previous_secret` on the peer's destination-side config (= the value the source is currently signing with)
2. Deploy
3. Coordinate with the source product to swap their `signing_secret` to a new value
4. Source deploys; both old and new signatures verify
5. Update your `previous_secret` to null (or drop the key); the new value is now the only accepted one
6. Deploy

## 10. Common pitfalls

| Symptom | Likely cause |
|---|---|
| Webhook returns 401 `unknown_trust_key` | Peer's `trust_key` mismatch — confirm the source is sending the value you have in your peer registry |
| Webhook returns 400 `bad_signature` | `current_secret` differs from peer's `signing_secret`. Confirm both, then check the replay window. |
| 422 `rejected_schema` in `inbound_share_messages` | Source is sending a payload that doesn't match the intent's schema — check `processing_error` for details |
| Outbox row stuck in `queued` | Queue worker isn't running, OR peer config is missing `webhook_url`/`signing_secret` (job calls `markFailedPermanent`) |
| Outbox row in `retry_scheduled` forever | Peer endpoint always returns 5xx — check destination's logs; once 5 attempts pass it dead-letters |
| Outbox row in `dead_lettered` after recovery | `Sharing::redeliver($id)` re-queues with attempts=0 |
| `/auth/handoff` returns to `/login?error=handoff_provider_missing` | `<AccountSwitcherProvider>` isn't mounted on the destination — wrap your `app/layout.tsx` |
