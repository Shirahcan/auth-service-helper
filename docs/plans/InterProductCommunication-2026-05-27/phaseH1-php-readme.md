# Phase H1 — PHP helper README + `Sharing/` usage doc

**Repo:** `auth-service-helper`
**Spec section:** §7 "PHP Helper Surface" — consumer-facing documentation
**Depends on:** Entire Phase B + C + D + E (the surface being documented must exist and be tested via Phase G)

## Goal

Give PHP product authors a single canonical place to learn the `Sharing/`
surface end-to-end: a discoverable top-level section in `README.md` that
shows a complete Studendly → Portify round-trip, plus a deep API reference
in `docs/Sharing_Usage.md` modeled on the existing
`docs/AuthServiceClient_Usage.md` and `docs/User_Model.md`.

Every code snippet in both documents MUST be copy-pasteable against the
real shipped API — no pseudocode, no `// ...` placeholders for required
arguments. The Phase G round-trip harness is the source of truth; mirror
its exact call shapes.

## Files

- **Modify:** `README.md` — add new top-level section "User Sharing & Cross-Product Communication" under the existing TOC entry block; insert section content after the existing `AuthServiceClient` section.
- **Create:** `docs/Sharing_Usage.md` — full API reference (every public method on the `Sharing` facade, every built-in payload class, every typed exception).

## Steps

### Step 1 — Inventory the surface before writing a line

Before editing either file, list out (in your scratch buffer):

1. Every public method on `AuthService\Helper\Sharing\Facades\Sharing` — names, parameter signatures, return types, thrown exceptions. Source: Phase C4's facade implementation.
2. Every built-in payload class under `src/Sharing/Intents/Builtin/` — class name, constructor signature, intent slug, JSON-schema reference. Source: Phase B7a/B7b/B7c.
3. Every typed exception under `src/Sharing/Exceptions/` (e.g., `UserShareCollisionException`, `HandoffTokenInvalidException`, `EnvelopeSignatureMismatchException`, `IntentNotRegisteredException`, `OutboundDeadLetteredException`). Source: Phase C2a + B3 + B4 + B6 + E5.
4. Every event class destinations subscribe to (`InboundShareReceived`, `InboundHandoffCompleted`). Source: Phase D3.
5. Every published artifact (config keys, migrations, routes). Source: Phase B1 + D1 + D4 + E1.

This list IS the table-of-contents for `Sharing_Usage.md`. If a method
isn't shipped by the phases above, do NOT document it.

### Step 2 — Add the README section

In `README.md`, after the closing fence of the existing `AuthServiceClient`
section, insert a new top-level `## User Sharing & Cross-Product Communication`
section. Also add the new section to the README's table of contents (preserve
the existing TOC formatting).

Subsections (in order):

1. **Quick start — Source side (Studendly → Portify)**
   - One fenced PHP block showing `Sharing::shareUser(...)` → `Sharing::sendPayload(...)` → `Sharing::mintHandoffToken(...)` with redirect.
   - Use the EXACT framing from spec §7 "Source-Side API" (steps 1–3).
   - Show `use AuthService\Helper\Sharing\Facades\Sharing;` and
     `use AuthService\Helper\Sharing\Intents\Builtin\ServicePurchasePayload;`
     at the top of the block.
2. **Destination side — Register handlers for `InboundShareReceived`**
   - Show `config/authservice.php` `'sharing' => [...]` block from spec §7 "Destination-Side API".
   - Show `EventServiceProvider::$listen` registration.
   - Show one full handler class (the `ServicePurchaseHandler` example from spec §7).
3. **Custom intents**
   - Show `IntentRegistry::register(...)` from spec §6, plus a minimal `SharePayload` implementation skeleton (3-line class with the contract methods).
4. **Operational concerns**
   - One sub-paragraph each: outbox status surface (link to `Sharing::getDeliveryStatus`, `Sharing::listFailed`, `Sharing::redeliver`), dead-letter handling (when entries land in `dead_lettered`, who sees them, how to retry), signing-secret rotation (helper supports current + previous secrets during the rotation window — point at `config('authservice.sharing.signing_secret_env')` and the optional `..._PREVIOUS` envvar pattern from Phase B3).
5. **Full API reference**
   - One paragraph that says "For the complete API reference, see [`docs/Sharing_Usage.md`](docs/Sharing_Usage.md)."

Target length: ~150 lines added to `README.md`. If it grows past 250
lines, MOVE detail into `Sharing_Usage.md`.

### Step 3 — Write `docs/Sharing_Usage.md`

Mirror the format/depth of `docs/AuthServiceClient_Usage.md` exactly:

- H1 title + 2-line summary
- Table of Contents (linked)
- "Installation" section (composer require already covered upstream — just point at root README)
- "Configuration" section — full annotated `config/authservice.php` `'sharing'` block, every key, every env var, default values
- "The `Sharing` Facade" section — one H3 per public method:
  - `shareUser()`, `sendPayload()`, `mintHandoffToken()`, `revokeShare()`,
    `resolveCollision()`, `waitForMergeCompletion()`,
    `getDeliveryStatus()`, `listFailed()`, `redeliver()`,
    `lastInboundFor()`
  - Each method: signature, params table, return type/structure, throws (typed exceptions), one usage example
- "Built-in Payloads" section — one H3 per payload class:
  - `ServicePurchasePayload`, `ProfileSyncPayload`, `DocumentAddedPayload`,
    `StatusUpdatePayload`, `ReferralPayload`, `InvitePayload`,
    `RevocationNoticePayload`
  - Each: intent slug, constructor signature, required vs optional fields, full PHP example
- "Custom Intents" section — `IntentRegistry::register`, `SharePayload` interface contract, JSON-schema file structure, example
- "Inbound Events" section — `InboundShareReceived`, `InboundHandoffCompleted` — class shape, available properties, handler example
- "Exceptions" section — every typed exception, when thrown, recovery pattern
- "Outbox / Inbox Tables" section — schema for `outbound_share_messages`, `inbound_share_messages`, `sharing_handoff_log`; state machine diagram for outbox (ASCII art mirroring spec §9)
- "Operational Runbooks" section — sub-headings: monitoring outbox lag, draining DLQ, rotating HMAC secrets, rotating trust keys

Target length: ~400–600 lines. If over 600, split into sibling docs
(e.g., `Sharing_Payloads.md`) and link from the index — do NOT exceed
the 400-line plan-file limit by forcing everything into one doc.

### Step 4 — Verify every code example compiles / matches reality

For each fenced PHP block in both files:

1. Open the referenced source file in the helper repo.
2. Confirm class name, namespace, constructor signature, return type match.
3. Confirm imports (`use ...`) point at the actual published namespace
   (`AuthService\Helper\Sharing\...`).
4. Run a syntax-only pass: `php -l` on a scratch file containing each
   snippet wrapped in `<?php`.

If any snippet references a method/class that does NOT exist in the
shipped Phase B–E surface, FIX THE DOC (do not add the method retroactively).

### Step 5 — Spell-check, link-check, commit

```bash
cd C:\Users\benpl\Documents\GitHub\auth-service-helper

# Sanity: confirm both files exist + non-empty
test -s README.md && test -s docs/Sharing_Usage.md && echo OK

# Optional: render with any local markdown linter you have (project doesn't ship one)

git add README.md docs/Sharing_Usage.md
git commit -m "feat(sharing): phase H1 — PHP helper README + Sharing/ usage doc

Adds top-level 'User Sharing & Cross-Product Communication' section to
README with a Studendly→Portify quick-start, destination-handler example,
custom-intent registration snippet, and operational notes. Full API
reference moved to docs/Sharing_Usage.md (every facade method, every
built-in payload, every typed exception, outbox/inbox schema, runbooks).

Phase: H1 of docs/plans/InterProductCommunication-2026-05-27/"
```

## Note for downstream phases

Phase H3 (migration guide) links into this doc — do NOT re-document the
API surface there; H3 is a step-by-step integration walkthrough that
ASSUMES the reader knows where to look up method signatures.
