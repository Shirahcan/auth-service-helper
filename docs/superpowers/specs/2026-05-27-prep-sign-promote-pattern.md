# Prep–Sign–Promote pattern (v1.4 of inter-product Sharing)

> Builds directly on the v1.3 Sharing protocol (`docs/superpowers/specs/2026-05-26-inter-product-communication-design.md`). Read that first.

## Why

v1.3 ships one-way **push** (source signs an envelope, peer receives + dispatches an event) and **handoff redirects** (cross-product login). It assumes the resource being acted on already exists on both sides.

But many cross-product flows need to **provision a resource on a peer before the orchestrator's external condition is even known**. Examples:

- Studendly needs the student to sign Portify's visa-rep agreement BEFORE Studendly takes payment. If the student abandons the cart, no Portify agreement record should exist.
- Studendly needs a Portify case-number reservation locked in BEFORE admission outcome is known. If admission fails, the reservation should disappear without trace.
- Portify needs to embed a Studendly-served status iframe for a student-in-progress, but the student should only see real data after the visa case is paid.

The naïve approach — write the permanent record on the peer, then maybe-delete-later — pollutes the peer's data store, complicates refund logic, and gives attackers a cheap surface to spam.

**Prep–Sign–Promote** is a two-phase commit at the cross-product boundary: peer creates a **TEMP** record with TTL + GC; orchestrator confirms an external condition (payment); the temp record gets **PROMOTED** to permanent. Abandoned flows GC away cleanly.

## State machine

```
prepared ── /embed/{slug}/{prep_id}/submit ──► signed ── /promote (auth'd) ──► promoted
   │                                              │                              │
   │                                              │                              └── status frozen; permanent record exists
   │                                              │
   └── TTL (prepared_until) ──┐                   └── TTL (signed_until) ─┐
                              ▼                                            ▼
                            expired ─────── sharing:gc-prep ───────► row deleted
```

- `prepared`: peer accepted the prepare RPC, no signature yet. TTL: short (24h default) — minimizes abuse surface.
- `signed`: iframe submitted signature data. TTL: longer (72h default) — student has committed, want to give time to complete payment.
- `promoted`: orchestrator confirmed external condition. Row frozen + linked to permanent record; never auto-deleted (audit retention).
- `expired`: TTL passed. Eligible for GC. Signed data scrubbed with the row.

## Data model: `prep_resources`

| Column | Notes |
|---|---|
| `id` (uuid pk) | |
| `source_service_id` (uuid) | The orchestrator |
| `idempotency_key` (varchar 255) | UNIQUE with `source_service_id` |
| `intent` (varchar 128) | e.g., `agreement_sign` — routes to handler |
| `intent_version` (varchar 16) | Pin handler version |
| `source_resource` (json) | `{type, id}` — orchestrator's checkout/cart/case ref |
| `student_data` (json) | `{external_id, email, name}` — passed by orchestrator |
| `payload` (json) | Intent-specific request fields (e.g., `agreement_slug`) |
| `signed_data` (json, nullable) | Captured at `/submit` time |
| `return_to` (varchar 2048) | Where the embed should redirect after submit (optional) |
| `status` (string 32) | enum: `prepared|signed|promoted|expired` |
| `prepared_at` (timestamp) | |
| `signed_at` (timestamp, nullable) | |
| `promoted_at` (timestamp, nullable) | |
| `expires_at` (timestamp) | Driven by status: `prepared` → +24h; `signed` → +72h; `promoted` → null (never auto-expires) |
| `permanent_resource_id` (uuid, nullable) | Linked record after promote |
| `created_at` / `updated_at` | |

Indexes:
- `UNIQUE(source_service_id, idempotency_key)` — the idempotency guarantee
- `(status, expires_at)` — GC sweep
- `(intent, status)` — ops dashboards

## HTTP API

All `/api/v1/sharing/prep/*` endpoints require the **same HMAC envelope signature** as v1.3 webhooks. The middleware is reused.

### POST `/api/v1/sharing/prep/prepare`

**Body** (envelope payload):
```json
{
  "operation": "prepare",
  "intent_slug": "agreement_sign",
  "source_resource": { "type": "checkout", "id": "ckt_8f2a91" },
  "student_data": { "external_id": "stu_123", "email": "...", "name": "..." },
  "payload": { "agreement_slug": "visa-rep-agreement" },
  "return_to": "https://studendly.app/checkout/agreements/done"
}
```

**Response 200** (newly prepared OR idempotent return of existing):
```json
{
  "prep_id": "ag_prep_abc123",
  "embed_url": "https://portify.app/sharing/embed/agreement_sign/ag_prep_abc123",
  "expires_at": "2026-05-28T12:00:00Z",
  "status": "prepared"
}
```

If an entry with the same `(source_service_id, idempotency_key)` already exists, return the existing prep_id + current status. Never duplicate.

### GET `/sharing/embed/{intent_slug}/{prep_id}`

Public route (no auth header — `prep_id` is the unguessable security token). Returns HTML rendered by the intent's handler. Validates: prep_id exists, status ∈ {`prepared`, `signed`}, not expired. 404 otherwise.

The HTML is iframe-embeddable; it submits via JS to:

### POST `/sharing/embed/{intent_slug}/{prep_id}/submit`

Public; called by iframe JS on signature complete. Captures `signed_data`, flips status `prepared → signed`, extends `expires_at` to +`ttl_signed_hours`. Returns `{ status: "signed", signed_at }`.

The peer's intent handler validates the signed_data shape before accepting.

### POST `/api/v1/sharing/prep/{prep_id}/promote`

**Body** (envelope payload):
```json
{
  "operation": "promote",
  "share_id": "<uuid of auth-service user-share created post-payment>",
  "trigger_proof": { "payment_id": "pay_...", "amount_cents": 40000 }
}
```

Required preconditions:
- entry exists for `prep_id`
- envelope's `source_service_id` matches the entry's
- entry status is `signed`
- entry not expired
- `share_id` resolves on auth-service to a valid share targeting THIS peer

On success the handler's `promote()` runs, the temp entry flips to `promoted` with `permanent_resource_id` set, and the response carries:
```json
{ "permanent_resource_id": "...", "state": "active" | "pending_activation" }
```

`409 already_promoted` is **idempotent** — returns the existing `permanent_resource_id`. `404 prep_expired_or_missing` requires the orchestrator to re-`prepare`.

### GET `/api/v1/sharing/prep/{prep_id}/status`

Envelope-signed read. Returns `{ prep_id, intent, status, signed_at, promoted_at, expires_at, permanent_resource_id }`. Source-of-truth for reconciliation when postMessage acks get lost.

## Intent handler contract

```php
interface PrepIntentHandler
{
    public static function intentSlug(): string;
    public static function intentVersion(): string;

    /** Render the iframe surface (Blade view, raw HTML, whatever). */
    public function render(PrepResource $temp): \Symfony\Component\HttpFoundation\Response;

    /** Validate + store signature data. Called from POST /embed/{slug}/{id}/submit. */
    public function submit(PrepResource $temp, array $signedData): void;

    /** Create the permanent record. Returns the permanent resource id + activation state. */
    public function promote(PrepResource $temp, array $share): PromoteResult;
}

final class PromoteResult
{
    public function __construct(
        public readonly string $permanentResourceId,
        public readonly string $state,  // 'active' | 'pending_activation' | etc.
    ) {}
}
```

Built-in: `AgreementSignHandler` — creates a `signed_agreement` row (product-side table — peer product implements it), returns `permanent_resource_id = signed_agreement.id`, `state = pending_activation` until the orchestrator's downstream activation trigger (admission success, etc.).

## Orchestrator-side facade

```php
// One round-trip, returns enough to render the iframe.
$prep = Sharing::prepare(
    peerSlug: 'portify',
    intentSlug: 'agreement_sign',
    idempotencyKey: 'studendly:checkout:ckt_8f2a91:agreement:visa-rep',
    sourceResource: ['type' => 'checkout', 'id' => 'ckt_8f2a91'],
    studentData:    ['external_id' => $user->id, 'email' => $user->email, 'name' => $user->name],
    payload:        ['agreement_slug' => 'visa-rep-agreement'],
    returnTo:       url('/checkout/agreements/done'),
);
// → PrepResult { prepId, embedUrl, expiresAt, status }

// (orchestrator renders <iframe src={prep.embedUrl}> ... user signs ... iframe acks via postMessage ...)
// (orchestrator collects payment locally)

// Post-payment: kick off user-share on auth-service FIRST
$share = Sharing::shareUser(
    userId: $user->id,
    targetService: 'portify',
    intent: 'visa_rep_agreement',
);

// Then promote the temp resource on the peer
$promoted = Sharing::promote(
    peerSlug: 'portify',
    prepId: $prep->prepId,
    shareId: $share->id,
    triggerProof: ['payment_id' => $payment->id, 'amount_cents' => $payment->amount_cents],
);
// → PromoteResult { permanentResourceId, state }

// Reconciliation tool
$status = Sharing::prepStatus('portify', $prep->prepId);
```

## Why share_id is the linker (not student_data)

`student_data` is **descriptive** — passed at prepare time so the peer can render the agreement with the right name + email. It does NOT prove anything about identity.

`share_id` is **authoritative** — issued by auth-service AFTER payment. The peer's `/promote` endpoint cross-checks `share_id` against auth-service to confirm:
- the orchestrator has actually created a share to THIS peer
- the share is for the right student (the share's source_user_uuid matches)
- the share is `active` (not revoked between create + promote)

This is the critical security boundary: pre-payment, the peer has only descriptive temp data. Post-payment, the auth-service share record is what unlocks promotion. An attacker who can hit `/prepare` cannot promote without forging an auth-service-issued share_id (impossible without auth-service credentials).

## GC

```
php artisan sharing:gc-prep [--before=<iso-date>] [--dry-run]
```

- Deletes rows where `status IN ('prepared', 'signed', 'expired')` AND `expires_at < now()`
- `promoted` rows are **NEVER** deleted (audit / legal retention concern)
- Recommended schedule: hourly
- Fires `PrepResourceExpired` event per row (for product-side observability)

## Configuration (auto-merged into `authservice.sharing.prep.*`)

| Key | Default | Purpose |
|---|---|---|
| `prep.ttl_prepared_hours` | 24 | TTL for unsigned entries |
| `prep.ttl_signed_hours` | 72 | TTL after signing (extended on `submit`) |
| `prep.embed_base_url` | `config('app.url')` | Base for the embed URL the orchestrator embeds. Override per-env. |
| `prep.gc_batch_size` | 500 | Rows per GC sweep |

## Events (product code listens)

| Event | Fires when | Listener pattern |
|---|---|---|
| `PrepResourceCreated` | After `/prepare` upsert (only for NEW rows, not idempotent returns) | Pre-warm caches; analytics |
| `PrepResourceSigned` | After `/submit` captures signed_data | Trigger send-acknowledgement email to student |
| `PrepResourcePromoted` | After `/promote` flips to permanent | Kick off case creation / case-activation flows |
| `PrepResourceExpired` | After GC marks row for deletion | Send abandoned-cart reminder |

## What this does NOT cover (out of scope for v1.4)

- Multi-step signing (e.g., two parties signing the same agreement) — single-signer only
- Server-side rendered template fetching API (`agreement_template_request` from earlier discussion) — handler owns the rendering; no separate template fetch
- Cross-product void/refund propagation — orchestrator drives refunds locally based on its own state; an `agreement_voided` envelope could be added later if peer-side void needs propagation

## Relationship to user-share kickoff

**User-share creation happens AFTER payment, BEFORE promote.** The peer's `/promote` endpoint REQUIRES a valid `share_id` and rejects without it. This guarantees:

1. No `user_shares` row exists for abandoned flows
2. No peer-side permanent record exists for abandoned flows
3. Payment is the legal trigger that creates the auth-service identity link
4. Promote is the operational action that materializes peer-side resources tied to that link

Product flow (Studendly-first):
```
1. Sharing::prepare         ─► peer creates temp { status: prepared }
2. iframe → /submit         ─► peer updates       { status: signed }
3. Studendly takes payment  ─► (local)
4. Sharing::shareUser       ─► auth-service creates user_share
5. Sharing::promote         ─► peer creates permanent + { status: promoted }
6. (Eventually) handoff     ─► student lands on peer with active session
```

Steps 4 + 5 are functionally one "post-payment kickoff" — if step 5 fails (e.g., peer-side bug), the share_id exists but the temp prep can be retried or voided. If step 4 fails, step 5 is impossible (404 share_invalid). Both directions are recoverable.
