# Sharing — cross-product user share + payload + handoff

The `Sharing/` namespace adds a complete cross-product communication layer:

- **Identity plane** — `Sharing::shareUser()`, `Sharing::mintHandoffToken()` (talks to auth-service `user_shares` + `handoff_tokens`)
- **Domain plane** — `Sharing::sendPayload()` (queued, signed envelope, retry+DLQ direct to the peer's webhook)
- **Inbox** — destination's `InboundShareReceived` + `InboundHandoffCompleted` Laravel events, mounted at `/api/v1/inbound/user-share` + `/api/v1/inbound/handoff/exchange` by `SharingServiceProvider::boot()`
- **Ops surface** — `Sharing::getDeliveryStatus()`, `Sharing::listFailed()`, `Sharing::redeliver()`, `sharing:purge-delivered` artisan command

## Config

Publish the skeleton config:

```bash
php artisan vendor:publish --tag=auth-service-helper-sharing-config
```

Configure peers in `config/authservice-sharing.php` (or env-driven equivalents):

```php
'source_service_id' => env('SHARING_SOURCE_SERVICE_ID'),
'internal_token'    => env('SHARING_INTERNAL_TOKEN'),
'replay_window_seconds' => 300,
'peers' => [
    'portify' => [
        // source-side (this app sends to portify)
        'webhook_url'       => env('PORTIFY_WEBHOOK_URL'),
        'signing_secret'    => env('PORTIFY_HMAC_SECRET'),
        'trust_key'         => env('PORTIFY_TRUST_KEY'),
        'target_service_id' => env('PORTIFY_SERVICE_ID'),

        // destination-side (this app receives from portify)
        'source_service_id' => env('PORTIFY_SERVICE_ID'),
        'current_secret'    => env('PORTIFY_HMAC_SECRET_INBOUND'),
        'previous_secret'   => env('PORTIFY_HMAC_SECRET_INBOUND_PREV'),
    ],
],
```

`previous_secret` enables zero-downtime HMAC rotation — set both during the swap, then drop the previous one.

## Source-side API

### Share a user

```php
use AuthService\Helper\Sharing\Facades\Sharing;

$result = Sharing::shareUser(
    userId: $user->id,
    targetService: 'portify',     // peer slug
    intent: 'service_purchase',
    grantedRoles: ['client'],
    metadata: ['order_id' => 'studendly:order:88421'],
);

if ($result->conflict) {
    // Same email exists on the destination; resolve via:
    //   Sharing::resolveCollision($result->conflict->id, 'merge', [...]);
    // or pass strictOnConflict: true to shareUser() to throw instead.
}
```

### Push a domain payload

```php
use AuthService\Helper\Sharing\Intents\Builtin\ServicePurchasePayload;

$row = Sharing::sendPayload(
    shareId: $result->id,
    intent: 'service_purchase',
    payload: new ServicePurchasePayload(
        orderId: 'studendly:order:88421',
        serviceSlug: 'study_visa',
        amountCents: 40000,
        currency: 'CAD',
        purchasedAt: now()->toIso8601String(),
        items: [['sku' => 'visa-app', 'amount_cents' => 40000]],
    ),
    idempotencyKey: 'studendly:order:88421',
    peerSlug: 'portify',
);

// $row is an OutboundShareMessage in status=queued. A DispatchOutboundShareJob
// has been pushed; the queue worker signs + POSTs to portify's webhook with
// 1m/5m/30m/2h/12h backoff on transient failures (5xx/408/429/network), and
// dead-letters after 5 attempts. 4xx (other) → failed_permanent immediately.
```

### Mint a handoff token (cross-product redirect)

```php
$mint = Sharing::mintHandoffToken(shareId: $share->id, nextPath: '/cases/abc');
return redirect($mint->redirectUrl); // https://portify.app/auth/handoff?token=...
```

The destination's Next helper (F1/F2) consumes the token and seamlessly arrives the user at `/cases/abc` already logged in.

## Destination-side: react to inbound events

Listen for the typed event in any `EventServiceProvider`:

```php
use AuthService\Helper\Sharing\Inbox\Events\InboundShareReceived;

Event::listen(InboundShareReceived::class, function (InboundShareReceived $event) {
    if ($event->intent === 'service_purchase') {
        /** @var ServicePurchasePayload $payload */
        $payload = $event->payload;
        OrderImporter::import($event->userId, $payload);
    }
});
```

The webhook controller already:
1. Verified the HMAC signature (current + previous secret rotation supported)
2. Checked the idempotency key against the inbox `UNIQUE(source_service_id, idempotency_key)`
3. Validated the payload against the intent's JSON-schema
4. Hydrated the typed `SharePayload` via `IntentRegistry`

By the time your listener runs, the row is `status=dispatched`.

## Ops surface

```php
$status = Sharing::getDeliveryStatus($shareId);
// DeliveryStatusSummary { pending, delivered, failed, deadLettered, lastDeliveredAt, lastAttemptAt }

$failed = Sharing::listFailed();
// Collection<OutboundShareMessage> in dead_lettered + failed_permanent

Sharing::redeliver($outboundMessageId); // re-queue a DEAD_LETTERED row (throws if not DLQ)
```

Periodic cleanup:

```bash
php artisan sharing:purge-delivered --before=2026-04-01
# default cutoff: 30 days ago
```

## Built-in payloads

| Intent | Payload class |
|---|---|
| `service_purchase` | `Intents\Builtin\ServicePurchasePayload` |
| `profile_sync` | `Intents\Builtin\ProfileSyncPayload` |
| `document_added` | `Intents\Builtin\DocumentAddedPayload` |
| `status_update` | `Intents\Builtin\StatusUpdatePayload` |
| `referral` | `Intents\Builtin\ReferralPayload` |
| `invite` | `Intents\Builtin\InvitePayload` |
| `revocation_notice` | `Intents\Builtin\RevocationNoticePayload` |

To register a custom intent:

```php
use AuthService\Helper\Sharing\Intents\IntentRegistry;

app(IntentRegistry::class)->register(
    'my_intent',
    MyIntentPayload::class,
    schemaPath: __DIR__ . '/schemas/my-intent.schema.json',
);
```

## Tests

The helper ships four test suites:

```bash
vendor/bin/phpunit --testsuite=Unit         # foundation (envelope, intents, repository, retry)
vendor/bin/phpunit --testsuite=Feature      # webhook controllers, middleware, routing
vendor/bin/phpunit --testsuite=Integration  # in-process source→destination round-trip
vendor/bin/phpunit --testsuite=Contract     # OpenAPI fragment pin (run `composer pull-contract-fixtures` to refresh)
```

## Architecture references

- Spec: `docs/superpowers/specs/2026-05-26-inter-product-communication-design.md`
- Implementation plan: `docs/plans/InterProductCommunication-2026-05-27/00_MASTER_INDEX.md`
