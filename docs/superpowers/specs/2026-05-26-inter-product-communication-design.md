# Inter-Product Communication Protocol & Base Infrastructure — Design

**Date:** 2026-05-26
**Status:** Approved (brainstorming complete; implementation plan to follow)
**Scope:** Three repos — `auth-service` (Laravel), `auth-service-helper` (PHP package), `auth-service-nextjs` (Next.js package)
**Primary author:** Claude Code (brainstorming session with @tech@shirah.co)

---

## 1. Problem Statement

Shirah operates multiple products (Portify, Studendly, more to come) that already share a central identity service (`auth-service`). The auth-service shipped a complete **user-sharing** surface on 2026-05-25 (`/api/v1/auth/user-shares*`, conflict resolution, webhooks). What is still missing is:

1. **A reusable client surface in `auth-service-helper`** so any PHP product can call `Sharing::shareUser(...)` without re-implementing the HTTP plumbing.
2. **A consistent inter-product protocol** for the *domain payload* that travels alongside a shared user ("user paid for Study Visa on Studendly, here is the order context"). Auth-service handles identity; it does not own domain data.
3. **A seamless cross-product redirect path** in `auth-service-nextjs` so that when a user clicks a Studendly CTA, they arrive on Portify already logged in (no second login).

The canonical use case driving the design: a student completes admission on Studendly over the course of months. They may pay for a Portify-fulfilled service early, mid-, or late in that journey. After payment, Studendly hands the user off to Portify (account + purchase context). The student may also return to Studendly later for incremental updates (new docs, status changes) which propagate to Portify automatically.

---

## 2. Key Decisions (Locked in Brainstorming)

| # | Decision | Rationale |
|---|---|---|
| D1 | **Scope:** Build the two helpers + add ONE minimal auth-service endpoint family (handoff-token mint + exchange). Existing user-shares surface is untouched. | Seamless-redirect requires server-mediated single-use tokens; building it in helpers alone would fragment the security boundary. |
| D2 | **Payload path:** Direct P2P (source → destination via helper's `TrustedServiceClient`). Auth-service carries only `{intent, correlation_id}` in `user_shares.metadata`. | Clean separation: auth-service owns identity, products own domain data. Payload never crosses the auth-service privacy boundary. Matches "Studendly notifies Portify" wording. |
| D3 | **Redirect UX:** CTA-driven (user clicks), repeatable across the long-lived relationship. Auto-redirect with brief splash is NOT the default. | Studendly journey is months long with multiple legitimate handoffs. Each CTA mints a fresh handoff token. |
| D4 | **Payload lifecycle:** Long-lived share + incremental payload updates over time. | A single `user_shares` row models the relationship. `sendPayload($shareId, $kind, …)` can be called repeatedly throughout the relationship. |
| D5 | **Reliability:** Background retry with dead-letter + status surface. Source enqueues; helper handles exponential backoff (1m, 5m, 30m, 2h, 12h, then DLQ). Source UI shows delivery status. | Required for a months-long pipeline where transient destination outages must not block real user actions. |
| D6 | **Schemas:** Helper ships an opinionated intent registry + typed payloads, products extend with custom intents. | "Consistent without rigid." Newer products plug in fast against starter catalogue; bespoke needs handled by `IntentRegistry::register(...)`. |
| D7 | **Multi-account on arrival:** Auto-add as second account in destination's switcher; switch to it; show toast "Now viewing as <name>". | Leverages existing multi-account infrastructure in both `auth-service-nextjs` and Portify. Zero clicks; reversible via the switcher. |

---

## 3. Architecture & Topology

### Two Planes

- **Identity plane** (auth-service): shares, conflicts, handoff tokens, sessions. System of record for *who* this user is across products.
- **Domain plane** (P2P between products via helpers): what was purchased, what changed, what to do about it. Auth-service never sees the payload.

### Sequence (Happy Path)

```
┌─────────────┐                  ┌──────────────┐                  ┌─────────────┐
│   Source    │   1. POST share  │ auth-service │                  │ Destination │
│ (Studendly) │ ────────────────►│              │ ────webhook────► │  (Portify)  │
│             │                  │ user-shares  │  user.share.*    │             │
│             │   2. mint        │              │                  │             │
│             │ ────token───────►│              │                  │             │
│             │                  │              │                  │             │
│             │   3. P2P payload (signed envelope, idempotent)     │             │
│             │ ──────────────────────────────────────────────────►│             │
│             │                                                    │             │
│             │   4. user clicks CTA, helper redirects with token  │             │
│             │ ──────► /auth/handoff?token=…&next=… ─────────────►│             │
│             │                                                    │             │
│             │                  ┌──────────────┐                  │             │
│             │                  │ auth-service │                  │             │
│             │                  │ 5. exchange  │◄──────────────── │             │
│             │                  │ token→session│  POST /handoff/  │             │
│             │                  │              │  exchange         │             │
│             │                  └──────────────┘ ────────────────►│             │
└─────────────┘                                  user_id, token,   └─────────────┘
                                                 short-lived
                                                 access creds
```

Steps 1, 2, 3 happen at PAYMENT TIME (or any time the relationship is established/updated). Step 4 happens whenever the user clicks a CTA — possibly weeks or months after step 3.

---

## 4. Auth-Service Additions (Only New Endpoints)

| Method | Path | Purpose | Auth |
|---|---|---|---|
| `POST /api/v1/auth/handoff-tokens` | Mint a single-use token tied to `(share_id, target_service_id, optional next_path)`, TTL ≈ 60s | `X-API-KEY` (source) |
| `POST /api/v1/auth/handoff-tokens/{token}/exchange` | Destination redeems token; auth-service validates (single-use atomic, unexpired, target matches caller), returns destination-scoped session creds + the resolved user record | `X-API-KEY` (destination) |

### Token Row Schema

```sql
CREATE TABLE handoff_tokens (
    id UUID PRIMARY KEY,
    token_hash VARCHAR(64) NOT NULL UNIQUE,         -- SHA-256 of opaque random token
    share_id UUID NOT NULL,                          -- references user_shares.id
    target_service_id UUID NOT NULL,                 -- must match X-API-KEY's service at exchange
    next_path VARCHAR(2048) NULL,                    -- optional deep-link target
    minted_by_service_id UUID NOT NULL,              -- source
    expires_at TIMESTAMP NOT NULL,                   -- = minted_at + 60s
    consumed_at TIMESTAMP NULL,
    consumed_by_service_id UUID NULL,
    replay_attempts INT DEFAULT 0,
    created_at TIMESTAMP NOT NULL,
    INDEX (share_id),
    INDEX (target_service_id, expires_at)
);
```

### Single-Use Enforcement (Atomic)

```php
$affected = DB::table('handoff_tokens')
    ->where('token_hash', $hash)
    ->whereNull('consumed_at')
    ->where('expires_at', '>', now())
    ->where('target_service_id', $callerServiceId)
    ->update(['consumed_at' => now(), 'consumed_by_service_id' => $callerServiceId]);

if ($affected !== 1) {
    DB::table('handoff_tokens')
        ->where('token_hash', $hash)
        ->increment('replay_attempts');
    throw new HandoffTokenInvalidException(/* reason */);
}
```

### Auth-Service Iframe Addition (Scope Note)

The existing account-switcher iframe needs a new postMessage handler: `ADD_ACCOUNT_WITH_TOKEN`. ~30 lines of frontend code. Counted in scope as part of the "minimal auth-service additions" — confirmed during design review.

---

## 5. The Wire Envelope

Every source → destination payload delivery uses this shape, sent as `POST /api/v1/inbound/user-share` on destination (path mounted by helper's ServiceProvider):

```json
{
  "envelope_version": "1",
  "message_id": "msg_01HZ…",
  "correlation_id": "share_01HX…",
  "intent": "service_purchase",
  "intent_version": "1.0",
  "source_service_id": "<uuid>",
  "target_service_id": "<uuid>",
  "user_id": "<uuid>",
  "idempotency_key": "studendly:order:88421",
  "issued_at": "2026-05-26T14:02:03Z",
  "payload": { /* intent-specific */ }
}
```

### Required Headers

| Header | Value | Purpose |
|---|---|---|
| `X-Trust-Key` | Per-peer secret | Trusted-services authentication (already used by `TrustedServiceClient`) |
| `X-Signature` | `t=<unix>,v1=<hmac_sha256(t + "." + body, shared_secret)>` | Payload integrity + replay-window enforcement (±5min) |
| `X-Idempotency-Key` | Same as `envelope.idempotency_key` | Header surface for fast pre-body dedup check |
| `Content-Type` | `application/json` | — |

Signing secret is a separate config from trust key (different rotation cadence is acceptable).

---

## 6. Intent Registry — Starter Catalogue

The helper ships these built-in intents with typed PHP DTOs + TypeScript types + JSON-schema validators:

| Intent | Use Case | Required Fields (illustrative) |
|---|---|---|
| `service_purchase` | User bought X on source, fulfill on destination | `order_id, service_slug, amount_cents, currency, purchased_at, items[]` |
| `profile_sync` | Source's user profile changed | `fields_changed[], snapshot{}` |
| `document_added` | New doc available from source | `document_id, kind, source_url, uploaded_at, metadata{}` |
| `status_update` | Admission/case status moved forward | `status, previous_status, effective_at, notes?` |
| `referral` | User was referred from source | `referral_code, referrer_user_id?, campaign?` |
| `invite` | Source invites user to join destination | `invitation_message, granted_roles[], expires_at?` |
| `revocation_notice` | Source is unsharing this user (auth-service revoke complement) | `reason, effective_at` |

Products register custom intents via:

```php
IntentRegistry::register(
    'studendly.application_submitted',
    StudendlyApplicationSubmittedPayload::class,    // implements SharePayload interface
    schema: __DIR__.'/schemas/application-submitted.json',
);
```

Intent slugs follow `<source_namespace>.<event_name>` convention to avoid collision across products.

---

## 7. PHP Helper Surface (`auth-service-helper`)

### New Namespace Layout

```
src/
  Sharing/                                  ← NEW
    Client/
      UserShareClient.php                   (wraps /user-shares*)
      HandoffTokenClient.php                (wraps /handoff-tokens*)
    Outbox/
      OutboundShareMessage.php              (Eloquent model)
      DispatchOutboundShareJob.php          (queued, retry + DLQ)
      SharingOutboxRepository.php
    Inbox/
      InboundShareMessage.php               (Eloquent model)
      Http/Controllers/InboundShareWebhookController.php
      Events/InboundShareReceived.php
      Events/InboundHandoffCompleted.php
    Envelope/
      ShareEnvelope.php                     (DTO + validator)
      EnvelopeSigner.php                    (HMAC-SHA256, ±5min window)
      EnvelopeVerifier.php
      IdempotencyGuard.php
    Intents/
      IntentRegistry.php                    (singleton)
      Contracts/SharePayload.php            (interface)
      Builtin/
        ServicePurchasePayload.php
        ProfileSyncPayload.php
        DocumentAddedPayload.php
        StatusUpdatePayload.php
        ReferralPayload.php
        InvitePayload.php
        RevocationNoticePayload.php
    Facades/
      Sharing.php                           (main facade)
```

### Source-Side API

```php
use AuthService\Helper\Sharing\Facades\Sharing;

// 1. Establish the share (idempotent — re-call returns existing row)
$share = Sharing::shareUser(
    userId: $student->uuid,
    targetService: 'portify',
    intent: 'service_purchase',
    grantedRoles: ['client'],
);
// → ShareResult { share_id, status, conflict? }

// 2. Push the domain payload (queued, retried, DLQ'd on permanent failure)
Sharing::sendPayload(
    shareId: $share->id,
    intent: 'service_purchase',
    payload: new ServicePurchasePayload(
        order_id: 'studendly:order:88421',
        service_slug: 'study_visa',
        amount_cents: 40000,
        currency: 'CAD',
        purchased_at: now(),
        items: [/* … */],
    ),
    idempotencyKey: 'studendly:order:88421',
);
// → OutboundShareMessage { id, status: 'queued' }

// 3. When user clicks the CTA, mint a handoff token
$handoff = Sharing::mintHandoffToken(
    shareId: $share->id,
    nextPath: '/cases/'.$caseId,
);
return redirect($handoff->redirectUrl);

// 4. (later) Push an update
Sharing::sendPayload(
    shareId: $share->id,
    intent: 'status_update',
    payload: new StatusUpdatePayload(
        status: 'admission_offer_accepted',
        effective_at: now(),
    ),
    idempotencyKey: 'studendly:status:'.$timestamp,
);

// 5. Revoke when relationship ends
Sharing::revokeShare($share->id, reason: 'admission_withdrawn');
```

### Destination-Side API

```php
// config/authservice.php (extended)
'sharing' => [
    'webhook_path' => '/api/v1/inbound/user-share',
    'trust_key_env' => 'STUDENDLY_TRUST_KEY',
    'signing_secret_env' => 'STUDENDLY_HMAC_SECRET',
    'idempotency_retention_days' => 30,
],

// EventServiceProvider
protected $listen = [
    InboundShareReceived::class => [
        ServicePurchaseHandler::class,
        ProfileSyncHandler::class,
        StatusUpdateHandler::class,
    ],
    InboundHandoffCompleted::class => [
        LogHandoffArrival::class,
    ],
];

// Handler example (product code)
class ServicePurchaseHandler {
    public function handle(InboundShareReceived $event): void {
        if ($event->intent !== 'service_purchase') return;

        $payload = $event->payload; // ServicePurchasePayload (typed)
        $user = User::findOrFail($event->userId);

        Case::firstOrCreate(
            ['external_order_id' => $payload->order_id],
            [
                'user_id' => $user->id,
                'service_slug' => $payload->service_slug,
                /* ... */
            ],
        );
    }
}
```

The webhook controller is mounted by the helper's ServiceProvider. Products don't write routing code. Controller responsibilities:

1. Verify `X-Signature` (HMAC + ±5min window) and `X-Trust-Key` (trusted-services table)
2. Check `X-Idempotency-Key` against `inbound_share_messages` (drop duplicates → return 200 + `already_processed: true`)
3. Validate payload against intent's JSON-schema
4. Persist envelope to `inbound_share_messages` (audit trail)
5. Dispatch `InboundShareReceived` event synchronously
6. Return `202 Accepted`

### Published Migrations

| Table | Purpose |
|---|---|
| `outbound_share_messages` | Source-side outbox: `queued, in_flight, delivered, retry_scheduled, dead_lettered, failed_permanent` |
| `inbound_share_messages` | Destination-side inbox: every received envelope (incl. duplicates, logged but not re-dispatched) |
| `sharing_handoff_log` | Audit: every handoff token mint/exchange (observability + replay-detection alerts) |

### What Stays Unchanged

- `AuthServiceClient` (608 lines today) — untouched; `Sharing/` is purely additive
- `TrustedServiceClient` — used internally by `DispatchOutboundShareJob` (thin wrapper that pre-signs the envelope)
- Existing auth/session/account-switcher surface — fully unchanged

---

## 8. Next Helper Surface (`auth-service-nextjs`)

### New Module Layout

```
src/
  sharing/                                  ← NEW
    HandoffClient.ts                        (server-side: mint via PHP backend)
    consumeHandoffToken.ts                  (server-side: redeems at auth-service)
    multiAccountAutoAdd.ts                  (browser: pushes new account into switcher)
    types.ts
  app/
    auth/handoff/route.ts                   (Next App Router handler — copy-pasta installer)
  hooks/
    useHandoffArrival.ts                    (browser: reads `?_toast=…`)
  components/
    HandoffArrivalToast.tsx                 ("Now viewing as <name>" banner)
```

### Source-Side

The mint happens in the **PHP backend** (`Sharing::mintHandoffToken`). Next on the source side just renders a link/button using the URL the backend produced. No new Next code on the source side. Existing `PostMessageClient`/`AccountSwitcher` infrastructure stays untouched.

### Destination-Side `/auth/handoff` Route

Copy-pasta installer:

```ts
import { consumeHandoffToken } from '@benbraide/auth-service-nextjs/sharing';

export async function GET(req: Request) {
  const url = new URL(req.url);
  const token = url.searchParams.get('token');
  const next = url.searchParams.get('next') ?? '/';

  if (!token) return Response.redirect(new URL('/login', url), 302);

  const result = await consumeHandoffToken({
    token,
    backendUrl: process.env.NEXT_PUBLIC_BACKEND_URL!,
    serviceApiKey: process.env.NEXT_SERVICE_API_KEY!,
  });

  if (!result.ok) {
    return Response.redirect(
      new URL(`/login?error=handoff_${result.reason}`, url),
      302,
    );
  }

  const bridge = new URL('/auth/handoff/landing', url);
  bridge.searchParams.set('account_uuid', result.user.uuid);
  bridge.searchParams.set('session_token', result.sessionToken);
  bridge.searchParams.set('next', next);
  bridge.searchParams.set('toast', `Now viewing as ${result.user.name}`);
  return Response.redirect(bridge, 302);
}
```

`consumeHandoffToken` internally:
1. POSTs to destination's PHP backend `/api/v1/inbound/handoff/exchange` (helper-provided controller)
2. That controller calls auth-service `POST /handoff-tokens/{token}/exchange` with `X-API-KEY`
3. Auth-service validates: single-use (atomic), unexpired, target matches caller
4. Returns `{ user, session_token, share_id, next_path }`

### Multi-Account Auto-Add (Client-Side Landing)

```tsx
'use client';
import { useEffect } from 'react';
import { useSearchParams, useRouter } from 'next/navigation';
import { useAccountSwitcher, multiAccountAutoAdd } from '@benbraide/auth-service-nextjs';

export default function HandoffLanding() {
  const params = useSearchParams();
  const router = useRouter();
  const { addAccount, switchAccount } = useAccountSwitcher();

  useEffect(() => {
    (async () => {
      await multiAccountAutoAdd({
        accountUuid: params.get('account_uuid')!,
        sessionToken: params.get('session_token')!,
        switcher: { addAccount, switchAccount },
      });
      const next = params.get('next') ?? '/';
      const toast = params.get('toast') ?? '';
      router.replace(`${next}${toast ? `?_toast=${encodeURIComponent(toast)}` : ''}`);
    })();
  }, []);

  return <div className="…">Setting up your account…</div>;
}
```

`multiAccountAutoAdd` uses the EXISTING iframe `PostMessageClient` to:
1. Send `ADD_ACCOUNT_WITH_TOKEN` message to the auth-service iframe (NEW message type)
2. The iframe (which owns auth-service cookies for this destination) writes the session cookie for the new user_id under the destination's domain
3. iframe sends back `SESSION_CHANGED` with the new account appended
4. Caller switches to the new account via existing `switchAccount(uuid)` flow

### New PostMessage Type

```ts
// message-protocol.ts (one addition)
export const MESSAGE_TYPES = {
  // …existing…
  ADD_ACCOUNT_WITH_TOKEN: 'add-account-with-token',  // NEW
  // …
} as const;

export interface AddAccountWithTokenPayload {
  accountUuid: string;
  sessionToken: string; // short-lived from auth-service exchange
}
```

### What Stays Unchanged

- `AccountSwitcherProvider`, `AccountSwitcher`, `AccountAvatar` — all untouched
- `useUser`, `useSession`, `useAccountSwitcher` — untouched
- All existing message types — untouched (additive only)

---

## 9. Reliability — Outbox/Inbox Lifecycle

### Source Side (`outbound_share_messages`)

```
queued → in_flight → delivered                          (happy path)
                  ↘ retry_scheduled → in_flight … (transient failure, exp backoff)
                                    ↘ dead_lettered (after N attempts or 4xx permanent)
```

Retry schedule: `1m, 5m, 30m, 2h, 12h` (5 attempts then DLQ).
- **Transient** = network/timeout/5xx → schedule retry
- **Permanent** = 4xx except 408 and 429 → dead-letter immediately
- 408/429 → schedule retry with backoff

Helper exposes `Sharing::redeliver($messageId)` for manual recovery from DLQ.

### Destination Side (`inbound_share_messages`)

```
received → validated → dispatched              (synchronous, before returning 202)
                    ↘ rejected_signature       (400)
                    ↘ rejected_schema          (422)
                    ↘ duplicate                (200, logged, not re-dispatched)
```

Handlers are dispatched as Laravel events INSIDE the request — the 202 ack means "we have it durably and will process it." Slow handlers can opt into `ShouldQueue` individually.

---

## 10. Idempotency Model

Two distinct keys, two distinct purposes:

| Key | Lives on | Prevents |
|---|---|---|
| `correlation_id` (= `share_id`) | every envelope | Conflating different shares of the same user |
| `idempotency_key` (caller-supplied) | every envelope, header `X-Idempotency-Key` | Duplicate domain effects from retries (e.g., two orders) |

Destination's `inbound_share_messages` has `UNIQUE(source_service_id, idempotency_key)` with 30-day retention. Beyond 30 days, duplicate detection lapses (acceptable: replays that old are pathological).

---

## 11. Collision Handling Surfaced to the Helper

When `Sharing::shareUser()` is called and auth-service returns 409 with a conflict row (target already has a different user with the same email), the helper bubbles it as a typed exception:

```php
try {
    $share = Sharing::shareUser(/* … */);
} catch (UserShareCollisionException $e) {
    // $e->conflictId, $e->sourceUserId, $e->targetExistingUserId, $e->resolutionUrl
    Sharing::resolveCollision(
        conflictId: $e->conflictId,
        strategy: 'link_alias',  // or 'reject_distinct' / 'merge_into_target' / 'cancel'
    );
}
```

Async merges (with ≥50 rows to migrate) return a job handle; helper exposes `Sharing::waitForMergeCompletion($conflictId, timeout: 30)` for source UIs that want to block briefly.

---

## 12. Revocation Flow (Both Directions)

| Trigger | Effect |
|---|---|
| Source calls `Sharing::revokeShare($shareId)` | Auth-service marks `user_shares.status = revoked`, expires destination sessions, removes granted roles, fires `user.share.revoked` webhook |
| Destination receives `user.share.revoked` webhook | Helper persists envelope with intent `revocation_notice`, dispatches `InboundShareReceived` for product code (cleanup local data, notify user, etc.) |
| User-initiated unshare from destination UI | Calls `Sharing::revokeShare($shareId)` (auth-service permission gate still applies — both parties can revoke per existing auth-service rules) |

---

## 13. Edge Cases (Explicit)

| Case | Handling |
|---|---|
| Handoff token replayed (already consumed) | Auth-service exchange returns `410 Gone`; destination route redirects to `/login?error=handoff_expired`. `replay_attempts` incremented; alert if >3 for one token. |
| Handoff token from wrong target | Exchange returns `403`; destination redirects to `/login?error=handoff_mismatch`. |
| User arrives at destination before payload delivered | Destination landing page shows skeleton + polls `Sharing::lastInboundFor($shareId)`. After ~30s with no payload, soft-warn "We're still receiving your data from Studendly…" — soft, not error. |
| User arrives, destination has no `share` row yet (webhook lag) | Same as above. Handoff token itself proves the share exists; destination just hasn't received the webhook yet. |
| Source dispatches an update for an already-revoked share | Destination webhook returns `410`; source's outbox marks `dead_lettered` with reason `share_revoked`; surfaces in source's admin UI. |
| Trust key rotated mid-flight | Helper supports two active keys (current + previous) during rotation window; verifies against either. |
| Clock skew between products | ±5min signature window; helper exposes `EnvelopeSigner::extendWindowForTest()` for tests. |

---

## 14. Testing Strategy

| Repo | Unit | Contract / Integration |
|---|---|---|
| **auth-service** | `HandoffTokenController`: mint, exchange, single-use, expiry, target-mismatch, replay (Pest, RefreshDatabase) | OpenAPI doc + contract test fixture published alongside; consumed by both helpers' contract tests |
| **auth-service-helper (PHP)** | `EnvelopeSigner`, `IdempotencyGuard`, `IntentRegistry`, each built-in payload validator, `DispatchOutboundShareJob` retry/DLQ math | Round-trip integration: spin up two Laravel apps in-process (source + destination), wire helper on both, walk full `shareUser → sendPayload → webhook → event handler` flow. `Http::fake()` for auth-service calls. |
| **auth-service-nextjs** | `consumeHandoffToken` (success, expired, mismatched target), `multiAccountAutoAdd` postMessage protocol | Playwright: full source→destination redirect in a 2-tab harness using the built-in starter app |

---

## 15. Out of Scope (YAGNI)

- Central "all shares across all products" dashboard — auth-service docs already exist
- Pull-based payload mode (rejected in D2)
- Synchronous payload delivery mode (rejected in D5 — standardized on queued + status surface)
- Per-user consent UI before share (auth-service docs are explicit: shares are service-to-service)
- Real-time presence ("user is currently viewing destination")
- A separate "audit ledger" for payload deliveries in auth-service — outbox/inbox in the helpers is sufficient

---

## 16. Phased Delivery Shape (Preview)

The full implementation plan will be written separately via the `writing-plans` skill. Preview:

- **Phase A** — Auth-service handoff-token endpoints + iframe `ADD_ACCOUNT_WITH_TOKEN` postMessage handler
- **Phase B** — PHP helper `Sharing/` namespace foundation: envelope, intent registry, signer/verifier, idempotency guard
- **Phase C** — PHP helper source-side facade: `UserShareClient`, `HandoffTokenClient`, `Sharing::shareUser/mintHandoffToken/revokeShare`
- **Phase D** — PHP helper inbox: webhook controller, `InboundShareMessage`, event dispatching
- **Phase E** — PHP helper outbox: `OutboundShareMessage`, `DispatchOutboundShareJob`, retry+DLQ machinery, status surface
- **Phase F** — Next helper `sharing/` module: `consumeHandoffToken`, `/auth/handoff` route, multi-account auto-add, new postMessage type
- **Phase G** — End-to-end integration test harness (2-app round-trip in PHP; 2-tab Playwright in Next)
- **Phase H** — Docs + starter-app updates in both helpers; consumer-product migration guide

Each phase will be split into sub-phases (~3k token budget per phase file) per the project's `00_MASTER_INDEX.md` discipline.

---

## 17. Open Questions for the Implementation Plan

The following are intentionally deferred to the implementation-planning step (they don't change the architecture, only the execution sequencing):

1. **Auth-service signing secret rotation UX** — where does the admin manage per-pair HMAC secrets? Existing trusted-services UI, or new screen?
2. **Outbox table location** — bundled in the consumer product's main DB (default), or pluggable connection for products that want to isolate it?
3. **Destination's `inbound/handoff/exchange` controller** — generic enough to mount via the helper's ServiceProvider, or product-customizable hook for pre-session-write logic?
4. **Default queue connection** — assume `redis` (the Portify/Studendly norm), or detect and warn?
5. **TypeScript-PHP type sync** — manual mirror, or generator (e.g., `php-types-to-ts`)?

---

## 18. Related References

- Auth-service user-sharing docs: `auth-service/project/docs/features/user-sharing/` (README, api-reference, webhooks, sequence-diagrams, troubleshooting, security-review)
- Auth-service user-sharing implementation plan: `auth-service/project/docs/plans/user-sharing-2026-05-25/00_MASTER_INDEX.md`
- Auth-service user-sharing original spec: `auth-service/project/docs/superpowers/specs/2026-05-25-user-sharing-design.md`
