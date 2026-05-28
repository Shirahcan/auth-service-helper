# Sharing cookbook — worked examples

Recipes for common cross-product scenarios. Pair with `docs/api-reference.md`.

## Table of contents

1. [Send a one-shot domain event to a peer](#1-send-one-shot)
2. [Receive a domain event from a peer](#2-receive-one-shot)
3. [Cross-product redirect (handoff, no agreement)](#3-handoff)
4. [Cross-product agreement signing — Studendly orchestrates Portify's agreement](#4-prep-orchestrator)
5. [Cross-product agreement signing — Portify is the peer that owns the agreement](#5-prep-peer)
6. [Reverse flow — Portify-first: embed Studendly's agreement](#6-prep-reverse)
7. [Custom intent — pre-reserved document upload slot](#7-custom-intent)
8. [Custom intent handler — override the built-in `agreement_sign`](#8-override-handler)
9. [Bulk re-deliver after a peer outage](#9-bulk-redeliver)
10. [Forensic check — "did the peer get my payload?"](#10-forensic)
11. [Rotate a peer's HMAC secret with zero downtime](#11-rotate-secret)

---

## 1. Send one-shot

Source product (Studendly) tells Portify that a service was purchased.

```php
use AuthService\Helper\Sharing\Facades\Sharing;
use AuthService\Helper\Sharing\Intents\Builtin\ServicePurchasePayload;

DB::transaction(function () use ($order, $share) {
    Sharing::sendPayload(
        shareId:        $share->id,
        intent:         'service_purchase',
        payload: new ServicePurchasePayload(
            orderId:     "studendly:order:{$order->id}",
            serviceSlug: 'study_visa',
            amountCents: $order->amount_cents,
            currency:    $order->currency,
            purchasedAt: $order->paid_at->toIso8601String(),
            items:       $order->items->map(fn ($i) => $i->toShareDto())->all(),
        ),
        idempotencyKey: "studendly:order:{$order->id}",
    );
});
```

The payload sits in `outbound_share_messages`. The queue worker signs, POSTs, retries with the 1m/5m/30m/2h/12h backoff schedule on transient failures (5xx/408/429/network), and dead-letters after 5 attempts. 4xx (other than 408/429) fails permanent immediately.

## 2. Receive one-shot

Portify (destination) reacts to a Studendly purchase.

```php
// app/Providers/EventServiceProvider.php
protected $listen = [
    \AuthService\Helper\Sharing\Inbox\Events\InboundShareReceived::class => [
        \App\Listeners\Sharing\ServicePurchaseHandler::class,
    ],
];
```

```php
// app/Listeners/Sharing/ServicePurchaseHandler.php
namespace App\Listeners\Sharing;

use AuthService\Helper\Sharing\Inbox\Events\InboundShareReceived;
use AuthService\Helper\Sharing\Intents\Builtin\ServicePurchasePayload;

class ServicePurchaseHandler
{
    public function handle(InboundShareReceived $event): void
    {
        if ($event->intent !== 'service_purchase') return;

        /** @var ServicePurchasePayload $p */
        $p = $event->payload;

        \App\Models\Order::firstOrCreate(
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

The webhook has already done schema validation + idempotency dedupe before this fires.

## 3. Handoff

Studendly (source) sends a logged-in user to Portify without password re-entry.

```php
$share = Sharing::shareUser(
    userId: $user->id,
    targetService: 'portify',
    intent: 'visa_application',
);

$mint = Sharing::mintHandoffToken(
    shareId: $share->id,
    nextPath: "/cases/{$case->id}",
);

return redirect($mint->redirectUrl);
// → https://portify.app/auth/handoff?token=...&next=/cases/abc
```

On the Next side, Portify's `app/auth/handoff/route.ts` (created via `createHandoffRouteHandler`) consumes the token and forwards to `/auth/handoff/landing`, which uses `<HandoffLanding>` to drive `multiAccountAutoAdd` then redirect to the `next` path with a `_toast=Now+viewing+as+...` query param.

## 4. Prep orchestrator

Studendly is the orchestrator. The student must sign **Portify's** visa-rep agreement before Studendly takes payment.

### Step A — backend prepares (pre-payment)

```php
// Studendly's CheckoutController::showAgreements
$prep = Sharing::prepare(
    peerSlug: 'portify',
    intentSlug: 'agreement_sign',
    idempotencyKey: "studendly:checkout:{$cart->id}:visa-rep",
    sourceResource: ['type' => 'checkout', 'id' => $cart->id],
    studentData:    [
        'external_id' => $user->id,
        'email'       => $user->email,
        'name'        => $user->name,
    ],
    payload:  ['agreement_slug' => 'visa-rep-agreement'],
    returnTo: url('/checkout/agreements/done'),
);

return view('checkout.agreements', ['prep' => $prep]);
```

### Step B — front-end renders the iframe

```tsx
import { PrepEmbed } from '@benbraide/auth-service-nextjs/sharing';

export function AgreementStep({ prep }: { prep: PrepResultLike }) {
  return (
    <PrepEmbed
      prepResult={prep}
      onSigned={async ({ prepId }) => {
        await fetch('/checkout/mark-agreement-signed', {
          method: 'POST',
          body: JSON.stringify({ prepId }),
        });
        // Continue to payment step
        router.push('/checkout/payment');
      }}
      onFailed={({ error, message }) => {
        toast.error(`Signing failed: ${message ?? error}`);
      }}
      height={700}
    />
  );
}
```

### Step C — backend promotes (post-payment)

```php
// Studendly's CheckoutController::finalize
DB::transaction(function () use ($cart, $payment, $prep) {
    // 1. Create the auth-service share — post-payment gate
    $share = Sharing::shareUser(
        userId: $cart->user_id,
        targetService: 'portify',
        intent: 'visa_rep_agreement',
    );

    // 2. Promote the temp prep into a permanent record on Portify
    $promoted = Sharing::promote(
        peerSlug: 'portify',
        prepId:   $prep->prepId,
        shareId:  $share->id,
        triggerProof: [
            'payment_id'   => $payment->id,
            'amount_cents' => $payment->amount_cents,
        ],
        idempotencyKey: "studendly:checkout:{$cart->id}:visa-rep",
    );

    // 3. Link Portify's permanent record to our cart
    $cart->update([
        'portify_share_id'      => $share->id,
        'portify_agreement_id'  => $promoted->permanentResourceId,
        'portify_state'         => $promoted->state,
    ]);
});
```

The retry-safe flow:
- If `Sharing::shareUser` fails, no promote happens — orchestrator retries from step 1
- If `Sharing::promote` fails network-wise, retry with the same `idempotencyKey` — 409 `already_promoted` returns the existing `permanent_resource_id`
- If `Sharing::promote` returns 403 `share_invalid`, the share was revoked — error to ops

## 5. Prep peer

Portify is the peer in the scenario from §4. The product implements ONE listener to act on promotion.

```php
// app/Providers/EventServiceProvider.php
protected $listen = [
    \AuthService\Helper\Sharing\Prep\Events\PrepResourcePromoted::class => [
        \App\Listeners\Sharing\AgreementPromotedHandler::class,
    ],
];
```

```php
namespace App\Listeners\Sharing;

use AuthService\Helper\Sharing\Prep\Events\PrepResourcePromoted;
use App\Models\Case;
use App\Models\SignedAgreement;

class AgreementPromotedHandler
{
    public function handle(PrepResourcePromoted $event): void
    {
        $prep = $event->prep;

        if ($prep->intent !== 'agreement_sign') return;

        // Resolve / create local user (the share guarantees identity)
        $user = \App\Models\User::firstOrCreate(
            ['external_id' => $prep->student_data['external_id']],
            [
                'email' => $prep->student_data['email'],
                'name'  => $prep->student_data['name'],
            ],
        );

        // Persist the signature as a domain-level SignedAgreement
        SignedAgreement::create([
            'id'                => $event->permanentResourceId,
            'user_id'           => $user->id,
            'template_slug'     => $prep->payload['agreement_slug'],
            'template_version'  => $prep->intent_version,
            'signature_typed'   => $prep->signed_data['signature'],
            'signed_at'         => $prep->signed_data['agreed_at'],
            'source_service_id' => $prep->source_service_id,
            'source_resource'   => $prep->source_resource,
            'status'            => 'pending_activation',
        ]);

        // Create a Case in pending_activation state
        Case::firstOrCreate(
            ['source_resource_id' => $prep->source_resource['id']],
            [
                'user_id'           => $user->id,
                'agreement_id'      => $event->permanentResourceId,
                'status'            => 'awaiting_activation_trigger',
                'source_service_id' => $prep->source_service_id,
            ],
        );
    }
}
```

The `state` returned to the orchestrator (`pending_activation`) is determined by the intent handler's `promote()` method. To return a custom state, override the handler (recipe §8).

## 6. Prep reverse

Portify-first flow: student starts on Portify wanting visa help; Portify wants Studendly's admission-services agreement signed before collecting payment.

The roles flip — Portify is now the orchestrator, Studendly is the peer:

```php
// Portify's IntakeController
$prep = Sharing::prepare(
    peerSlug: 'studendly',
    intentSlug: 'agreement_sign',
    idempotencyKey: "portify:case:{$caseDraft->id}:admission-services",
    sourceResource: ['type' => 'case_draft', 'id' => $caseDraft->id],
    studentData:    [
        'external_id' => $user->id,
        'email'       => $user->email,
        'name'        => $user->name,
    ],
    payload:  ['agreement_slug' => 'admission-services-agreement'],
    returnTo: url('/intake/done'),
);
```

Same `<PrepEmbed/>` component on the front-end. After payment Portify creates its own `shareUser` (Portify → Studendly) and calls `Sharing::promote('studendly', ...)`.

This works because every product runs the helper — `/sharing/embed/*` and `/api/v1/sharing/prep/*` are auto-mounted on both sides.

## 7. Custom intent

Pre-reserve a Portify document upload slot from Studendly. The slot only becomes a real `Document` row on Portify after Studendly's payment confirms.

### Define the handler (on Portify)

```php
namespace App\Sharing\Intents;

use AuthService\Helper\Sharing\Prep\Intents\PrepIntentHandler;
use AuthService\Helper\Sharing\Prep\PrepResource;
use AuthService\Helper\Sharing\Prep\PromoteResult;
use AuthService\Helper\Sharing\Prep\Intents\Exceptions\InvalidSignatureException;
use Symfony\Component\HttpFoundation\Response;

class DocumentUploadSlotHandler implements PrepIntentHandler
{
    public static function intentSlug(): string { return 'document_upload_slot'; }
    public static function intentVersion(): string { return '1.0'; }

    public function render(PrepResource $temp): Response
    {
        // Render a Blade view with a file uploader that posts to .../submit
        return response()->view('sharing.embed.document-upload', [
            'prep'         => $temp,
            'document_type' => $temp->payload['document_type'] ?? 'generic',
        ]);
    }

    public function submit(PrepResource $temp, array $signedData): void
    {
        if (!isset($signedData['file_path'], $signedData['mime_type'])) {
            throw new InvalidSignatureException('file_path and mime_type are required');
        }
        // file already uploaded by the Blade view's JS; signed_data records the location
    }

    public function promote(PrepResource $temp, array $share): PromoteResult
    {
        $doc = \App\Models\Document::create([
            'user_id'        => \App\Models\User::firstOrCreate(
                ['external_id' => $share['user_id']],
                $temp->student_data,
            )->id,
            'file_path'      => $temp->signed_data['file_path'],
            'mime_type'      => $temp->signed_data['mime_type'],
            'document_type'  => $temp->payload['document_type'],
            'status'         => 'pending_review',
            'source_share'   => $share['id'],
        ]);
        return new PromoteResult($doc->id, 'pending_review');
    }
}
```

### Register

```php
// app/Providers/SharingServiceProvider.php (your own)
use AuthService\Helper\Sharing\Prep\Intents\PrepIntentRegistry;

public function boot(): void
{
    $this->app->afterResolving(PrepIntentRegistry::class, function (PrepIntentRegistry $reg) {
        $reg->register('document_upload_slot', new \App\Sharing\Intents\DocumentUploadSlotHandler());
    });
}
```

### Call from orchestrator (Studendly)

```php
$prep = Sharing::prepare(
    peerSlug: 'portify',
    intentSlug: 'document_upload_slot',
    idempotencyKey: "studendly:checkout:{$cart->id}:transcript",
    sourceResource: ['type' => 'checkout', 'id' => $cart->id],
    studentData:    ['external_id' => $user->id, 'email' => $user->email, 'name' => $user->name],
    payload:        ['document_type' => 'transcript'],
);
```

The pattern is identical to `agreement_sign`. Only the handler's domain logic differs.

## 8. Override handler

The built-in `AgreementSignHandler` renders a minimal HTML scaffold. To use Portify's actual agreement Blade view, just register a new handler with the same slug:

```php
// app/Sharing/Intents/PortifyAgreementSignHandler.php
namespace App\Sharing\Intents;

use AuthService\Helper\Sharing\Prep\Intents\Builtin\AgreementSignHandler;
use AuthService\Helper\Sharing\Prep\PrepResource;
use Symfony\Component\HttpFoundation\Response;

class PortifyAgreementSignHandler extends AgreementSignHandler
{
    public function render(PrepResource $temp): Response
    {
        $template = \App\Models\SystemAgreementTemplate::where('slug', $temp->payload['agreement_slug'])->firstOrFail();
        return response()->view('sharing.embed.portify-agreement', [
            'prep'     => $temp,
            'template' => $template,
            'student'  => $temp->student_data,
        ]);
    }

    // Inherit submit() + promote() — typed-name validation + UUID promotion default
}
```

```php
// Register
$reg->register('agreement_sign', new \App\Sharing\Intents\PortifyAgreementSignHandler());
```

The new registration wins — the built-in version is overridden. The Blade view's JS still calls `.../submit` and posts back to `window.parent` with `{type: 'prep-signed', prep_id}` — that protocol is fixed (matches what `<PrepEmbed/>` listens for).

## 9. Bulk redeliver

After a 6-hour Portify outage, dead-letter the rows and bulk re-queue:

```php
$rows = Sharing::listFailed();
// Or filter:
$rows = OutboundShareMessage::where('status', 'dead_lettered')
    ->where('peer_slug', 'portify')
    ->where('dead_lettered_at', '>=', now()->subDay())
    ->get();

foreach ($rows as $row) {
    try {
        Sharing::redeliver($row->id);
        Log::info("Re-queued {$row->id}");
    } catch (\Throwable $e) {
        Log::error("Re-queue failed for {$row->id}: {$e->getMessage()}");
    }
}
```

Each row resets `attempts=0` and re-enters the 5-attempt backoff schedule. The `last_error` is preserved for audit.

## 10. Forensic

"Did Portify receive my payload?"

```php
$shareId = $share->id;

$summary = Sharing::getDeliveryStatus($shareId);
// $summary->delivered === 1 → yes, at least one delivery succeeded
// $summary->lastDeliveredAt → when

// Or grab the row directly:
$row = \AuthService\Helper\Sharing\Outbox\OutboundShareMessage::where('share_id', $shareId)
    ->where('peer_slug', 'portify')
    ->latest()
    ->first();

echo $row->status;                       // delivered | retry_scheduled | dead_lettered
echo $row->attempts;                     // how many tries
echo $row->last_response_status;         // 200, 202, 500, etc.
echo $row->delivered_at?->toIso8601String();
```

For the reverse — "did we get THEIR payload" — use `Sharing::lastInboundFor($shareId)` and inspect `processing_status` + `processing_error`.

## 11. Rotate secret

Studendly wants to rotate Portify's HMAC secret without downtime.

### Step 1 — On Portify, accept BOTH secrets

```php
// config/authservice-sharing.php — Portify's view of Studendly
'peers' => [
    'studendly' => [
        // ...
        'current_secret'  => env('STUDENDLY_HMAC_SECRET_INBOUND'),       // the new one
        'previous_secret' => env('STUDENDLY_HMAC_SECRET_INBOUND_PREV'),  // the old one
    ],
],
```

Deploy Portify.

### Step 2 — On Studendly, swap signing_secret

```php
// config/authservice-sharing.php — Studendly's view of Portify
'peers' => [
    'portify' => [
        // ...
        'signing_secret' => env('PORTIFY_HMAC_SECRET'),  // updated to new value
    ],
],
```

Deploy Studendly. New payloads sign with the new secret; Portify accepts via `current_secret`. In-flight payloads signed with the OLD secret still verify via `previous_secret`.

### Step 3 — Drop the old secret

After ~24h (longer than `replay_window_seconds` and any in-flight retry windows):

```php
'previous_secret' => null,
```

Deploy. The rotation is complete; only the new secret is accepted now.
