# Sharing protocol — API reference (v1.4)

Complete reference for every facade method, HTTP endpoint, event, intent handler, config key, and exception in the `Sharing/` namespace. Cross-links into the topic guides:

- **What is this?** → `docs/sharing.md`
- **Cross-product consumer setup** → `docs/sharing-consumer-migration.md`
- **Two-phase commit for resource provisioning** → `docs/sharing-prep.md`
- **Ops dashboards + retention** → `docs/sharing-observability.md`
- **Worked examples** → `docs/sharing-cookbook.md`

---

## Table of contents

- [Facade — `Sharing::*`](#facade)
  - [`shareUser`](#sharinguseruser)
  - [`mintHandoffToken`](#sharingminthandofftoken)
  - [`revokeShare`](#sharingrevokeshare)
  - [`resolveCollision`](#sharingresolvecollision)
  - [`waitForMergeCompletion`](#sharingwaitformergecompletion)
  - [`sendPayload`](#sharingsendpayload)
  - [`redeliver`](#sharingredeliver)
  - [`listFailed`](#sharinglistfailed)
  - [`getDeliveryStatus`](#sharinggetdeliverystatus)
  - [`lastInboundFor`](#sharinglastinboundfor)
  - [`prepare`](#sharingprepare) **(v1.4)**
  - [`promote`](#sharingpromote) **(v1.4)**
  - [`prepStatus`](#sharingprepstatus) **(v1.4)**
- [HTTP endpoints (peer side, auto-mounted)](#http-endpoints)
- [Events](#events)
- [Intent handlers](#intent-handlers)
- [Built-in payloads](#built-in-payloads)
- [Configuration](#configuration)
- [Exceptions](#exceptions)
- [Artisan commands](#artisan-commands)
- [Envelope format reference](#envelope)

---

## Facade

The `Sharing` facade resolves `\AuthService\Helper\Sharing\SharingService` from the container. Import with:

```php
use AuthService\Helper\Sharing\Facades\Sharing;
```

### `Sharing::shareUser()`

Establish (or look up) a `user_share` row on auth-service linking the current product to a target peer for a specific user.

```php
Sharing::shareUser(
    string $userId,
    string $targetService,
    ?string $intent = null,
    array $grantedRoles = [],
    array $metadata = [],
    bool $strictOnConflict = false,
): \AuthService\Helper\Sharing\Client\ShareResult
```

| Param | Type | Notes |
|---|---|---|
| `userId` | string (UUID) | Source-side user UUID (the one already authenticated in this product) |
| `targetService` | string | Peer slug (`'portify'`) OR a target_service_id UUID |
| `intent` | ?string | Free-form label for analytics (`'visa_rep_agreement'`, `'admission_assist'`). Optional. |
| `grantedRoles` | array | Role IDs to grant on the target side. Default `[]`. |
| `metadata` | array | Free-form attached to the share. Default `[]`. |
| `strictOnConflict` | bool | If true, throws `UserShareCollisionException` when the target already has a user with the same email. Default false. |

**Returns:** `ShareResult { id, userId, targetServiceId, status, grantedRoleIds, metadata, conflict }`

**Throws:**
- `\InvalidArgumentException` if `targetService` slug not in `authservice.sharing.peers.*`
- `UserShareCollisionException` if `strictOnConflict=true` and an email collision exists

**Example:**

```php
$share = Sharing::shareUser(
    userId: $user->id,
    targetService: 'portify',
    intent: 'visa_rep_agreement',
    grantedRoles: ['client'],
    metadata: ['order_id' => $order->id],
);
// $share->id is the auth-service share UUID — pass it to Sharing::promote later
```

---

### `Sharing::mintHandoffToken()`

Mint a single-use ~60s token that, when redeemed at the destination's `/auth/handoff` route, logs the user in without a password.

```php
Sharing::mintHandoffToken(
    string $shareId,
    ?string $nextPath = null,
): \AuthService\Helper\Sharing\Client\HandoffMintResult
```

| Param | Type | Notes |
|---|---|---|
| `shareId` | string | UUID returned by `shareUser()` |
| `nextPath` | ?string | Path the destination should land the user on AFTER the handoff completes |

**Returns:** `HandoffMintResult { token, redirectUrl, expiresAt }`

**Example:**

```php
$mint = Sharing::mintHandoffToken(
    shareId: $share->id,
    nextPath: '/cases/' . $case->id,
);
return redirect($mint->redirectUrl);
// → 302 to https://portify.app/auth/handoff?token=...&next=/cases/abc
```

---

### `Sharing::revokeShare()`

Revoke a previously-issued share. Active session tokens issued under it remain valid until their TTL; new handoff tokens cannot be minted.

```php
Sharing::revokeShare(string $shareId, ?string $reason = null): void
```

**Example:**

```php
Sharing::revokeShare($share->id, reason: 'user_requested_disconnect');
```

---

### `Sharing::resolveCollision()`

Resolve a collision flagged in `ShareResult.conflict`. Strategies: `'merge'` (merge target's user into source's), `'reject'` (abandon the share), `'create_new'` (create a new identity on target).

```php
Sharing::resolveCollision(
    string $conflictId,
    string $strategy,
    array $params = [],
): array
```

**Example:**

```php
$result = Sharing::resolveCollision(
    $shareResult->conflict->id,
    'merge',
    ['merge_target' => 'source'],
);
```

---

### `Sharing::waitForMergeCompletion()`

Poll until an async merge resolution finishes. Useful in tests or one-off scripts; product code usually listens for the auth-service webhook instead.

```php
Sharing::waitForMergeCompletion(string $conflictId, int $timeoutSeconds = 30): array
```

---

### `Sharing::sendPayload()`

Queue a signed envelope for delivery to a peer's webhook. Returns immediately; the actual POST happens on the queue worker.

```php
Sharing::sendPayload(
    string $shareId,
    string $intent,
    \AuthService\Helper\Sharing\Intents\Contracts\SharePayload $payload,
    string $idempotencyKey,
    ?string $peerSlug = null,
    ?string $targetServiceId = null,
    ?string $userId = null,
    ?string $sourceServiceId = null,
): \AuthService\Helper\Sharing\Outbox\OutboundShareMessage
```

| Param | Type | Notes |
|---|---|---|
| `shareId` | string | UUID linking this payload to a user_share (correlation_id) |
| `intent` | string | Intent slug (`'service_purchase'`, `'document_added'`, …) |
| `payload` | `SharePayload` | Typed payload object; must implement the contract |
| `idempotencyKey` | string | Per-event key; same key = same delivery, never duplicated |
| `peerSlug` | ?string | Falls back to `authservice.sharing.default_peer_slug` |
| `targetServiceId` | ?string | Falls back to `peers.{slug}.target_service_id` |
| `userId` | ?string | The user the payload is about (default '') |
| `sourceServiceId` | ?string | Falls back to `authservice.sharing.source_service_id` |

**Returns:** `OutboundShareMessage` in status `queued` (or `delivered` if the row already existed and shipped).

**Example:**

```php
use AuthService\Helper\Sharing\Intents\Builtin\ServicePurchasePayload;

Sharing::sendPayload(
    shareId: $share->id,
    intent: 'service_purchase',
    payload: new ServicePurchasePayload(
        orderId:     "studendly:order:{$order->id}",
        serviceSlug: 'study_visa',
        amountCents: 40000,
        currency:    'CAD',
        purchasedAt: now()->toIso8601String(),
        items:       [],
    ),
    idempotencyKey: "studendly:order:{$order->id}",
);
```

---

### `Sharing::redeliver()`

Re-queue a `DEAD_LETTERED` outbound row. Throws `RedeliveryNotPermittedException` for any other state.

```php
Sharing::redeliver(string $outboundMessageId): OutboundShareMessage
```

Resets `attempts=0`, sets `status=queued`, dispatches a fresh `DispatchOutboundShareJob`. The `last_error` field is preserved for audit.

---

### `Sharing::listFailed()`

Returns rows in `dead_lettered` OR `failed_permanent`, ordered newest first.

```php
Sharing::listFailed(): \Illuminate\Database\Eloquent\Collection
```

---

### `Sharing::getDeliveryStatus()`

Aggregate counts + last timestamps for an entire share's outbound history.

```php
Sharing::getDeliveryStatus(string $shareId): \AuthService\Helper\Sharing\Outbox\DeliveryStatusSummary
```

**Returns:** `DeliveryStatusSummary { shareId, pending, delivered, failed, deadLettered, lastDeliveredAt, lastAttemptAt }`

`pending` = `queued + retry_scheduled + in_flight`.

---

### `Sharing::lastInboundFor()`

Most-recent `InboundShareMessage` for a given share (by `correlation_id`, ordered by `received_at`).

```php
Sharing::lastInboundFor(string $shareId): ?\AuthService\Helper\Sharing\Inbox\InboundShareMessage
```

---

### `Sharing::prepare()` **(v1.4)**

Commission a TEMP resource on a peer via the Prep–Sign–Promote pattern. Idempotent on `(source_service_id, idempotency_key)`.

```php
Sharing::prepare(
    string $peerSlug,
    string $intentSlug,
    string $idempotencyKey,
    array $sourceResource,
    array $studentData,
    array $payload,
    ?string $returnTo = null,
): \AuthService\Helper\Sharing\Prep\Client\PrepResult
```

| Param | Type | Notes |
|---|---|---|
| `peerSlug` | string | Peer slug (`'portify'`) |
| `intentSlug` | string | Intent (`'agreement_sign'`, `'document_upload_slot'`, custom) |
| `idempotencyKey` | string | **Per-checkout** — `"studendly:checkout:{$cart->id}:visa-rep"`. Repeat calls with the same key return the existing prep_id. |
| `sourceResource` | array | `['type' => 'checkout', 'id' => 'ckt_8f2a91']` — orchestrator's reference |
| `studentData` | array | `['external_id' => $user->id, 'email' => ..., 'name' => ...]` |
| `payload` | array | Intent-specific data (e.g., `['agreement_slug' => 'visa-rep']`) |
| `returnTo` | ?string | URL the iframe redirects to after submit (optional) |

**Returns:** `PrepResult { prepId, embedUrl, expiresAt, status }` — `embedUrl` goes straight into `<PrepEmbed src={...}>` on the front-end.

**Throws:**
- `\InvalidArgumentException` if `peerSlug` not configured
- `\RuntimeException` on non-200 HTTP response

**Example:**

```php
$prep = Sharing::prepare(
    peerSlug: 'portify',
    intentSlug: 'agreement_sign',
    idempotencyKey: "studendly:checkout:{$cart->id}:visa-rep",
    sourceResource: ['type' => 'checkout', 'id' => $cart->id],
    studentData:    ['external_id' => $user->id, 'email' => $user->email, 'name' => $user->name],
    payload:        ['agreement_slug' => 'visa-rep-agreement'],
    returnTo:       url('/checkout/agreements/done'),
);
// Pass $prep->prepId, $prep->embedUrl to the front-end React/Vue/etc.
```

---

### `Sharing::promote()` **(v1.4)**

Flip a SIGNED prep entry into a permanent record on the peer. Requires a valid `share_id` (the **post-payment user-share gate**).

```php
Sharing::promote(
    string $peerSlug,
    string $prepId,
    string $shareId,
    array $triggerProof = [],
    ?string $idempotencyKey = null,
): \AuthService\Helper\Sharing\Prep\Client\PromoteResultDto
```

| Param | Type | Notes |
|---|---|---|
| `peerSlug` | string | Same peer as `prepare()` |
| `prepId` | string | UUID from `PrepResult.prepId` |
| `shareId` | string | Auth-service share UUID — must be created via `Sharing::shareUser` AFTER payment confirms |
| `triggerProof` | array | Audit trail data (`['payment_id' => ..., 'amount_cents' => ...]`) |
| `idempotencyKey` | ?string | Defaults to `"promote:{$prepId}"`. Pass the same key as `prepare()` for full-flow idempotency. |

**Returns:** `PromoteResultDto { permanentResourceId, state }`

**Idempotency:** HTTP 409 `already_promoted` from the peer is treated as success — returns the existing `permanent_resource_id`.

**Throws:**
- `\RuntimeException` on any other non-2xx (403 share_invalid, 404 prep_expired, 409 not_signed)

**Example:**

```php
// 1. Payment confirms
$payment = ProcessPaymentService::charge($cart);

// 2. Create the share — this is the post-payment gate
$share = Sharing::shareUser(
    userId: $user->id,
    targetService: 'portify',
    intent: 'visa_rep_agreement',
);

// 3. Promote on the peer
$promoted = Sharing::promote(
    peerSlug: 'portify',
    prepId: $prep->prepId,
    shareId: $share->id,
    triggerProof: ['payment_id' => $payment->id, 'amount_cents' => $payment->amount_cents],
    idempotencyKey: "studendly:checkout:{$cart->id}:visa-rep",  // same as prepare()
);

// $promoted->permanentResourceId is the peer-side resource (signed_agreement, case, etc.)
$cart->update(['portify_agreement_id' => $promoted->permanentResourceId]);
```

---

### `Sharing::prepStatus()` **(v1.4)**

Reconciliation read. Use when a postMessage ack got lost or you need to verify peer-side state.

```php
Sharing::prepStatus(string $peerSlug, string $prepId): \AuthService\Helper\Sharing\Prep\Client\PrepStatus
```

**Returns:** `PrepStatus { prepId, intent, status, signedAt, promotedAt, expiresAt, permanentResourceId }`

---

## HTTP endpoints

All routes auto-mounted by `SharingServiceProvider::boot()`. Consumer apps don't edit `routes/api.php`.

### Inbox (v1.3)

#### `POST /api/v1/inbound/user-share`

Middleware: `share-envelope.verify` (HMAC + replay window)

**Request body:** envelope JSON (see [Envelope format](#envelope)). The `payload` field is intent-specific.

**Responses:**
- `202 Accepted` → row persisted in `inbound_share_messages` with `processing_status='received'`. After schema validation + dispatch: `processing_status='dispatched'`, `InboundShareReceived` event fired.
- `200 OK` `{message_id, already_processed: true}` → duplicate idempotency_key
- `400` `invalid_envelope` / `unknown_intent`
- `401` `missing_trust_key` / `unknown_trust_key`
- `400` `bad_signature` / `replay_window`
- `422` `rejected_schema` → payload didn't validate against the intent's JSON-schema

#### `POST /api/v1/inbound/handoff/exchange`

Middleware: `share-helper.internal` (X-Internal-Token header check)

Called by the destination's own Next backend, not by external peers.

**Request body:** `{token: string}`

**Response 200:**
```json
{
  "user":          { "uuid": "...", "name": "...", "email": "..." },
  "session_token": "sess_...",
  "share_id":      "...",
  "next_path":     "/cases/abc"
}
```

Also fires `InboundHandoffCompleted` event.

### Prep–Sign–Promote (v1.4)

#### `POST /api/v1/sharing/prep/prepare`

Middleware: `share-envelope.verify`

**Request body (envelope payload):**
```json
{
  "operation":       "prepare",
  "intent_slug":     "agreement_sign",
  "intent_version":  "1.0",
  "source_resource": { "type": "checkout", "id": "ckt_8f2a91" },
  "student_data":    { "external_id": "...", "email": "...", "name": "..." },
  "payload":         { "agreement_slug": "visa-rep-agreement" },
  "return_to":       "https://studendly.app/checkout/done"
}
```

**Response 200** (new OR idempotent return of existing):
```json
{
  "prep_id":    "550e8400-e29b-41d4-a716-446655440000",
  "embed_url":  "https://portify.app/sharing/embed/agreement_sign/550e8400-...",
  "expires_at": "2026-05-29T12:00:00Z",
  "status":     "prepared"
}
```

Fires `PrepResourceCreated` ONLY on first insert; idempotent re-calls are silent.

**Errors:**
- `400` `invalid_envelope` / `invalid_operation` / `missing_intent_slug` / `unknown_intent_slug`

#### `GET /sharing/embed/{intent_slug}/{prep_id}`

Public; auth'd by the unguessable `prep_id`. No middleware.

Renders the intent handler's HTML. The default `AgreementSignHandler::render()` returns a typed-name form + JS that posts to `.../submit` on completion and `window.parent.postMessage({type:'prep-signed', prep_id})` on success.

**Responses:**
- `200 OK` HTML body
- `404` if `prep_id` missing, expired, or `intent_slug` doesn't match the row
- `410 Gone` if status is `promoted` or `expired`
- `500` if intent handler missing

#### `POST /sharing/embed/{intent_slug}/{prep_id}/submit`

Public; called by the iframe's JS.

**Request body:** intent-specific. Default agreement handler expects:
```json
{ "signature": "Jane Student", "agreed_at": "2026-05-27T10:00:00Z" }
```

**Response 200:**
```json
{ "status": "signed", "signed_at": "2026-05-27T10:00:01Z" }
```

Fires `PrepResourceSigned`. Status flips `prepared → signed`. TTL extends from `ttl_prepared_hours` to `ttl_signed_hours`.

**Errors:**
- `400` `invalid_body`
- `404` `not_found` (missing/expired/intent-mismatch)
- `409` `invalid_state` (already signed/promoted)
- `422` `handler_rejected` (intent handler threw — e.g., typed name mismatch)

#### `POST /api/v1/sharing/prep/{prep_id}/promote`

Middleware: `share-envelope.verify`

**Request body (envelope payload):**
```json
{
  "operation":     "promote",
  "share_id":      "auth-service-share-uuid",
  "trigger_proof": { "payment_id": "...", "amount_cents": 40000 }
}
```

**Response 200:**
```json
{ "permanent_resource_id": "...", "state": "pending_activation" }
```

Fires `PrepResourcePromoted`. Row's status flips `signed → promoted`. Row is now immortal (`GC_ELIGIBLE_STATES` excludes promoted).

**Errors:**
- `400` `missing_share_id` / `invalid_envelope` / `invalid_operation`
- `403` `share_invalid` (auth-service share doesn't resolve OR `status != 'active'`)
- `404` `prep_not_found` / `prep_expired`
- `409` `already_promoted` `{permanent_resource_id, state}` — **idempotent success path; client should treat as 200**
- `409` `not_signed` (must `/submit` first)
- `500` `no_handler` / `promotion_failed`
- `502` `share_lookup_failed` (auth-service unreachable)

#### `POST /api/v1/sharing/prep/{prep_id}/status`

Middleware: `share-envelope.verify`

**Request body (envelope payload):**
```json
{ "operation": "status" }
```

**Response 200:**
```json
{
  "prep_id":               "...",
  "intent":                "agreement_sign",
  "status":                "signed",
  "signed_at":             "2026-05-27T10:00:00Z",
  "promoted_at":           null,
  "expires_at":            "2026-05-30T10:00:00Z",
  "permanent_resource_id": null
}
```

---

## Events

Register listeners in any `EventServiceProvider`:

```php
protected $listen = [
    \AuthService\Helper\Sharing\Inbox\Events\InboundShareReceived::class => [
        \App\Listeners\Sharing\ServicePurchaseHandler::class,
    ],
];
```

### Inbox events (v1.3)

| Event | Fires when | Fields |
|---|---|---|
| `InboundShareReceived` | After webhook persisted + schema-validated + payload hydrated | `shareId, intent, intentVersion, userId, sourceServiceId, payload (SharePayload), messageId, correlationId, metadata` |
| `InboundHandoffCompleted` | After `InboundHandoffExchangeController` returned 200 | `shareId, userId, targetServiceId, nextPath, consumedAt` |

### Prep events (v1.4)

| Event | Fires when | Fields |
|---|---|---|
| `PrepResourceCreated` | New row from `/prepare` (NOT idempotent returns) | `prep` (PrepResource) |
| `PrepResourceSigned` | `/submit` captures signature | `prep` |
| `PrepResourcePromoted` | `/promote` creates permanent | `prep, permanentResourceId` |
| `PrepResourceExpired` | `sharing:gc-prep` BEFORE deletion | `prep` |

---

## Intent handlers

### Outbound payload contract (v1.3)

```php
namespace AuthService\Helper\Sharing\Intents\Contracts;

interface SharePayload
{
    public static function fromArray(array $raw): self;
    public function toArray(): array;
    public static function intentSlug(): string;
    public static function intentVersion(): string;
}
```

Register in `SharingServiceProvider::boot()`:
```php
$this->app->afterResolving(IntentRegistry::class, function (IntentRegistry $reg) {
    $reg->register('my_intent', MyPayload::class, schemaPath: '/path/to/schema.json');
});
```

### Prep handler contract (v1.4)

```php
namespace AuthService\Helper\Sharing\Prep\Intents;

interface PrepIntentHandler
{
    public static function intentSlug(): string;
    public static function intentVersion(): string;
    public function render(PrepResource $temp): \Symfony\Component\HttpFoundation\Response;
    public function submit(PrepResource $temp, array $signedData): void;
    public function promote(PrepResource $temp, array $share): PromoteResult;
}
```

Register via `PrepIntentRegistry::register('slug', new MyHandler())`.

Throw `InvalidSignatureException` from `submit()` to return 422.

---

## Built-in payloads

| Intent | Class | Schema |
|---|---|---|
| `service_purchase` | `Intents\Builtin\ServicePurchasePayload` | `Intents/Builtin/schemas/service-purchase.json` |
| `profile_sync` | `ProfileSyncPayload` | `profile-sync.json` |
| `document_added` | `DocumentAddedPayload` | `document-added.json` |
| `status_update` | `StatusUpdatePayload` | `status-update.json` |
| `referral` | `ReferralPayload` | `referral.json` |
| `invite` | `InvitePayload` | `invite.json` |
| `revocation_notice` | `RevocationNoticePayload` | `revocation-notice.json` |

Built-in prep handler:

| Intent | Class |
|---|---|
| `agreement_sign` | `Prep\Intents\Builtin\AgreementSignHandler` |

---

## Configuration

All keys live under `authservice.sharing.*` (the helper auto-merges `config/authservice-sharing.php` defaults).

### Top-level

| Key | Type | Default | Purpose |
|---|---|---|---|
| `source_service_id` | uuid | env `SHARING_SOURCE_SERVICE_ID` | This product's own service UUID |
| `internal_token` | string | env `SHARING_INTERNAL_TOKEN` | Shared secret between this Next + this PHP backend |
| `webhook_path` | string | `/api/v1/inbound/user-share` | Mount path for the inbound webhook |
| `handoff_exchange_path` | string | `/api/v1/inbound/handoff/exchange` | Mount path for the handoff exchange controller |
| `replay_window_seconds` | int | 300 | Allowable clock skew on envelope signatures |
| `idempotency_retention_days` | int | 30 | How long inbox dedupe records are kept |
| `default_peer_slug` | ?string | null | Fallback peer for `sendPayload()` when not specified |

### Per-peer (`peers.{slug}.*`)

| Key | Type | Default | Purpose |
|---|---|---|---|
| `webhook_url` | url | — | Where outbound payloads POST to |
| `signing_secret` | string | — | This product signs with this (peer verifies with their `current_secret`) |
| `trust_key` | string | — | Peer identifies this product by this header value |
| `target_service_id` | uuid | — | Peer's auth-service service UUID |
| `source_service_id` | uuid | — | Peer's auth-service service UUID (yes, same value — used for inbound verification) |
| `current_secret` | string | — | This product verifies inbound peer signatures with this |
| `previous_secret` | ?string | null | Old secret kept during HMAC rotation |
| `prep_base_url` | ?url | derived | Base for `/api/v1/sharing/prep/*` on the peer (defaults to deriving from `webhook_url`) |

### Prep sub-tree (`prep.*`)

| Key | Type | Default | Purpose |
|---|---|---|---|
| `ttl_prepared_hours` | int | 24 | TTL for unsigned entries |
| `ttl_signed_hours` | int | 72 | TTL after signing (extended on `/submit`) |
| `embed_base_url` | ?url | `app.url` | Base for the `embed_url` returned by `/prepare` |
| `gc_batch_size` | int | 500 | Max rows swept per `sharing:gc-prep` run |

---

## Exceptions

| Exception | Thrown by | Meaning |
|---|---|---|
| `Sharing\Exceptions\UserShareCollisionException` | `shareUser(strict=true)` | Target already has a user with the same email |
| `Sharing\Exceptions\HandoffTokenInvalidException` | `HandoffTokenClient::exchange` | Token expired / consumed / wrong target / unknown |
| `Sharing\Envelope\Exceptions\InvalidEnvelopeException` | Inbox webhook | Body missing required envelope fields |
| `Sharing\Envelope\Exceptions\EnvelopeSignatureMismatchException` | Inbox middleware | HMAC didn't match `current_secret` OR `previous_secret` |
| `Sharing\Envelope\Exceptions\EnvelopeReplayWindowException` | Inbox middleware | Signature timestamp outside ±`replay_window_seconds` |
| `Sharing\Intents\Exceptions\UnknownIntentException` | `IntentRegistry::hydrate` | Intent slug not registered |
| `Sharing\Outbox\Exceptions\RedeliveryNotPermittedException` | `redeliver()` | Row is not in `dead_lettered` state |
| `Sharing\Prep\Intents\Exceptions\InvalidSignatureException` | Prep handler's `submit()` | Used by handlers to reject signature data; controller maps to 422 |

---

## Artisan commands

### `sharing:purge-delivered`

Trims old `delivered` outbound_share_messages rows. Default cutoff: 30 days ago.

```bash
php artisan sharing:purge-delivered                 # 30 days
php artisan sharing:purge-delivered --before=2026-04-01
```

Never touches `dead_lettered` or `failed_permanent` rows.

### `sharing:gc-prep` **(v1.4)**

Sweeps expired `prep_resources` rows in states `prepared|signed|expired`. **NEVER touches `promoted`** (immortality for audit retention).

```bash
php artisan sharing:gc-prep
php artisan sharing:gc-prep --dry-run                # show what would be deleted
php artisan sharing:gc-prep --batch=2000             # override gc_batch_size
```

Fires `PrepResourceExpired` per row BEFORE deletion so listeners can capture data.

Recommended schedule:
```php
// app/Console/Kernel.php
$schedule->command('sharing:gc-prep')->hourly();
$schedule->command('sharing:purge-delivered')->dailyAt('03:00');
```

---

## Envelope

Every signed envelope on the wire has this canonical shape:

```json
{
  "envelope_version":  "1",
  "message_id":        "msg_01HZX...",
  "correlation_id":    "550e8400-...",
  "intent":            "service_purchase",
  "intent_version":    "1.0",
  "source_service_id": "uuid",
  "target_service_id": "uuid",
  "user_id":           "uuid-or-empty",
  "idempotency_key":   "studendly:order:88421",
  "issued_at":         "2026-05-27T10:00:00Z",
  "payload":           { /* intent-specific */ }
}
```

The HMAC signature header is:
```
X-Signature: t=<unix-seconds>,v1=<hex-hmac-sha256>
```

Where the HMAC input is `"{t}.{canonical_json}"`. `canonical_json` is the envelope rendered with `json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)` — both sides MUST produce byte-identical output.

Additional headers on outbound POSTs:
- `X-Trust-Key`: peer identifies the source
- `X-Idempotency-Key`: mirrors the envelope field, surfaced for proxy debugging
- `Content-Type: application/json`

The peer's `VerifyShareEnvelopeSignature` middleware reads `X-Trust-Key` to look up the peer config, then verifies the signature against `current_secret` (or `previous_secret` during rotation).

---

## Further reading

- **Cookbook** (`docs/sharing-cookbook.md`) — worked examples for every common scenario
- **Consumer migration** (`docs/sharing-consumer-migration.md`) — top-to-bottom integration checklist
- **Observability** (`docs/sharing-observability.md`) — dashboards, alerts, storage estimates
