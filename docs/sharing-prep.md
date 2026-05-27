# Prep–Sign–Promote (Sharing v1.4)

> Builds on v1.3 Sharing — read `docs/sharing.md` first.

A two-phase commit primitive for cross-product resource provisioning. The landing product (orchestrator) commissions a temp resource on a peer; only after confirming an external condition (typically payment) does the resource get promoted to permanent. Abandoned flows GC away cleanly. Reuses v1.3 envelope HMAC for authentication.

## When to use it

- **Use Prep–Sign–Promote** whenever a peer needs to create a record on behalf of the orchestrator AND the orchestrator hasn't yet confirmed the trigger (payment, admission outcome, etc.). Examples:
  - Cross-product agreement signing where the orchestrator collects payment
  - Pre-reserving a case number or document slot before commitment
  - Any resource whose existence on the peer should be deferred until payment success
- **Use v1.3 plain envelope payloads** for one-way push of events that are *already final* (`service_purchase`, `status_update`, etc.) — no two-phase commit needed.

## The pattern (3 steps)

```
┌─────────────────────────┐                          ┌─────────────────────────┐
│  Orchestrator           │                          │  Peer (resource owner)  │
│  (landing product)      │                          │                         │
└─────────────────────────┘                          └─────────────────────────┘
            │                                                    │
            │  ① Sharing::prepare(...)                          │
            ├──────────────────────────────────────────────────►│ create TEMP entry
            │                                            ┌─ status=prepared, TTL ~24h
            │  ◄────  { prep_id, embed_url, expires_at }  │
            │                                            └─
            │                                                    │
            │  ② <PrepEmbed prepResult={prep} onSigned={...}/>  │
            │                                                    │
            │      iframe served by peer:                       │
            │       - validates prep_id                         │
            │       - renders agreement via intent handler      │
            │       - captures e-sig, POSTs to .../submit       │
            │       - status: prepared → signed (TTL extended)  │
            │                                                    │
            │  ③ postMessage 'prep-signed'  ◄─────────────────  │
            │                                                    │
            │      (orchestrator collects payment locally)       │
            │      (Sharing::shareUser creates auth-service      │
            │       user_share record — POST-PAYMENT gate)       │
            │                                                    │
            │  ④ Sharing::promote(prep_id, share_id, ...)       │
            ├──────────────────────────────────────────────────►│ validate share_id
            │                                                   │ via auth-service
            │                                            ┌─ intent handler's promote()
            │                                            │  creates permanent record
            │                                            │  status: signed → promoted
            │  ◄────  { permanent_resource_id, state }    └─
            │                                                    │
```

Abandoned at any step before ④ → `sharing:gc-prep` cron deletes the temp entry; nothing pollutes the peer's permanent store.

## Quickstart (orchestrator side, PHP)

```php
use AuthService\Helper\Sharing\Facades\Sharing;

// 1. Pre-payment: prepare the peer's resource
$prep = Sharing::prepare(
    peerSlug: 'portify',
    intentSlug: 'agreement_sign',
    idempotencyKey: "studendly:checkout:{$cart->id}:visa-rep",
    sourceResource: ['type' => 'checkout', 'id' => $cart->id],
    studentData:    ['external_id' => $user->id, 'email' => $user->email, 'name' => $user->name],
    payload:        ['agreement_slug' => 'visa-rep-agreement'],
    returnTo:       url('/checkout/agreements/done'),
);
// → PrepResult { prepId, embedUrl, expiresAt, status }

// Pass $prep to the front-end:
return view('checkout.agreements', ['prep' => $prep]);
```

```tsx
// Front-end (Next or any React):
import { PrepEmbed } from '@benbraide/auth-service-nextjs/sharing';

<PrepEmbed
  prepResult={prep}
  onSigned={({ prepId }) => continueCheckout(prepId)}
  onFailed={({ error }) => showSignError(error)}
/>
```

```php
// 2. Post-payment: shareUser then promote
$share = Sharing::shareUser(
    userId: $user->id,
    targetService: 'portify',
    intent: 'visa_rep_agreement',
);

$promoted = Sharing::promote(
    peerSlug: 'portify',
    prepId: $prep->prepId,
    shareId: $share->id,
    triggerProof: ['payment_id' => $payment->id, 'amount_cents' => $payment->amount_cents],
    idempotencyKey: "studendly:checkout:{$cart->id}:visa-rep",
);
// → PromoteResultDto { permanentResourceId, state }
```

## Built-in: `agreement_sign`

Ships ready to use. The default `AgreementSignHandler` renders a minimal HTML page with a typed-name signature input and posts back to `/sharing/embed/agreement_sign/{prep_id}/submit`. To customise the rendered agreement, register your own handler with the same slug:

```php
// In your AppServiceProvider::boot
use AuthService\Helper\Sharing\Prep\Intents\PrepIntentRegistry;

$this->app->afterResolving(PrepIntentRegistry::class, function ($reg) {
    $reg->register('agreement_sign', new \App\Sharing\MyAgreementSignHandler());
});
```

Your handler implements `PrepIntentHandler` (render / submit / promote). The `promote()` step is where you write to your own domain tables (e.g. `signed_agreements`, `cases`).

## Custom intents (other resource types)

```php
namespace App\Sharing;

use AuthService\Helper\Sharing\Prep\Intents\PrepIntentHandler;
use AuthService\Helper\Sharing\Prep\PrepResource;
use AuthService\Helper\Sharing\Prep\PromoteResult;
use Symfony\Component\HttpFoundation\Response;

class DocumentUploadSlotHandler implements PrepIntentHandler
{
    public static function intentSlug(): string { return 'document_upload_slot'; }
    public static function intentVersion(): string { return '1.0'; }

    public function render(PrepResource $temp): Response
    {
        return new Response(view('sharing.embed.upload-slot', ['prep' => $temp])->render());
    }

    public function submit(PrepResource $temp, array $signedData): void
    {
        // Validate signedData shape; throw InvalidSignatureException on bad input
    }

    public function promote(PrepResource $temp, array $share): PromoteResult
    {
        $doc = Document::create([
            'user_id' => $share['user_id'],
            'file_url' => $temp->signed_data['file_url'],
            'status' => 'pending_review',
        ]);
        return new PromoteResult($doc->id, 'pending_review');
    }
}
```

Register it the same way as the override above. Don't forget to expose the orchestrator side via `Sharing::prepare(intentSlug: 'document_upload_slot', ...)`.

## State machine + GC

| State | Set by | Expires | GC'd? |
|---|---|---|---|
| `prepared` | `/prepare` | TTL `prep.ttl_prepared_hours` (24h default) | yes, if expired |
| `signed` | `/embed/.../submit` | TTL `prep.ttl_signed_hours` (72h default) | yes, if expired |
| `promoted` | `/promote` | never | **never** (audit retention) |
| `expired` | (state value reserved for explicit voiding) | n/a | yes |

Run the GC hourly:

```bash
# In your Laravel scheduler
$schedule->command('sharing:gc-prep')->hourly();

# Or manually
php artisan sharing:gc-prep [--dry-run] [--batch=500]
```

Fires `PrepResourceExpired` per row BEFORE deletion — listen if you want to log abandoned signatures.

## Security boundaries

- **`prep_id`** secures the iframe. It's unguessable (UUID) and short-lived; the embed page rejects requests with invalid/expired ids.
- **`share_id`** secures `/promote`. The peer cross-checks every promote request's `share_id` against auth-service. Without an auth-service-issued share, no promotion happens. This is what defers user-share creation to post-payment — the orchestrator MUST call `Sharing::shareUser` before `Sharing::promote`.
- **Envelope HMAC** secures `/prepare` and `/promote` via the v1.3 `share-envelope.verify` middleware. Same machinery as v1.3 outbound payload signing.

## Failure recovery

| Failure | Behavior | Recovery |
|---|---|---|
| Orchestrator network-blip on `/prepare` | Idempotent re-call returns existing `prep_id` | Retry the same `prepare()` call with the same idempotency_key |
| User abandons after signing, never pays | TTL expires; `sharing:gc-prep` deletes the temp + signed_data | No action needed; abandoned legal acceptance never took effect |
| Orchestrator promotes after `signed_until` TTL elapses | `/promote` returns 404 `prep_expired` | Re-`prepare`, re-sign, re-promote |
| Orchestrator double-promotes (queue retry) | 409 `already_promoted` — returns existing `permanent_resource_id` | None — promote() facade treats 409 as success |
| `share_id` invalid (revoked, wrong service) | 403 `share_invalid` | Verify `Sharing::shareUser` succeeded before promote; check share status |

For reconciliation, the orchestrator can poll `Sharing::prepStatus('portify', $prepId)` — returns `{status, signed_at, promoted_at, permanent_resource_id}`.

## Reverse flow (peer-first landing)

Symmetric — both products serve a `/sharing/embed/{slug}/{prep_id}` route and accept `/prepare` + `/promote`. When the student lands on Portify first, Portify is the orchestrator and Studendly is the peer. Same code paths, swapped roles.

## Config (`config/authservice-sharing.php`)

```php
'prep' => [
    'ttl_prepared_hours' => 24,
    'ttl_signed_hours'   => 72,
    'embed_base_url'     => env('SHARING_PREP_EMBED_BASE_URL'),  // default: app.url
    'gc_batch_size'      => 500,
],
```

## Events

| Event | Fires when | Listen for… |
|---|---|---|
| `PrepResourceCreated` | New row from `/prepare` (NOT idempotent re-calls) | Analytics, abuse detection |
| `PrepResourceSigned` | `/submit` captures signature | Send "we received your signature" email |
| `PrepResourcePromoted` | Permanent record created | Kick off case creation, send welcome email, fire downstream Sharing payloads |
| `PrepResourceExpired` | `sharing:gc-prep` about to delete | Send abandoned-cart reminder |

## See also

- v1.3 Sharing: `docs/sharing.md`
- Consumer migration: `docs/sharing-consumer-migration.md`
- Observability: `docs/sharing-observability.md`
- Spec: `docs/superpowers/specs/2026-05-27-prep-sign-promote-pattern.md`
