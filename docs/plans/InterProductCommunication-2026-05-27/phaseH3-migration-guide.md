# Phase H3 — Consumer-product migration guide

**Repo:** `auth-service-helper` (canonical home for cross-repo docs — both PHP and Next docs live here so the guide is searchable from one place)
**Spec section:** §7 + §8 — synthesised into an end-to-end integration walkthrough
**Depends on:** H1 (PHP API reference exists), H2 (Next-side starter snippets exist), entire Phase A–F (the surface being integrated must be shipped)

## Goal

A single step-by-step walkthrough that a product engineer (Portify-side
*or* greenfield) can follow top-to-bottom to wire their product into the
cross-product sharing fabric. Covers two personas in one document:

- **Persona A — existing product, destination only** (e.g. Portify
  today: receives shares from Studendly, no outbound shares of its own
  yet).
- **Persona B — greenfield product, both source and destination** (a
  hypothetical new product that needs the full two-way integration).

The guide ASSUMES the reader has read H1 + H2 once and now wants a
checklist. It is NOT an API reference.

## Files

- **Create:** `docs/MigrationGuide-CrossProductSharing.md`

## Steps

### Step 1 — Outline the doc structure

Top-level structure of `docs/MigrationGuide-CrossProductSharing.md`:

```
# Cross-Product Sharing — Migration Guide

## Overview
## Prerequisites
## Persona A — Existing product, destination only
  ### A.1 Install the helper package
  ### A.2 Publish + run migrations
  ### A.3 Configure environment variables
  ### A.4 Register InboundShareReceived listeners
  ### A.5 (Next) Wire /auth/handoff route + landing page
  ### A.6 Verify end-to-end with the starter apps
## Persona B — Greenfield, source + destination
  ### B.1 Everything from Persona A
  ### B.2 Decide intent slugs you'll emit
  ### B.3 Call Sharing::shareUser at the right product trigger
  ### B.4 Call Sharing::sendPayload at the right product trigger
  ### B.5 Mint handoff tokens for CTAs
  ### B.6 Test the round-trip
## Post-launch operations
  ### O.1 Monitor outbox health
  ### O.2 Redeliver from DLQ
  ### O.3 Rotate signing secrets
  ### O.4 Rotate trust keys
## Common pitfalls
## Links
```

### Step 2 — Fill in Persona A (destination-only)

For each sub-step, give a fenced command block + 1–2 sentence
explanation. Concretely:

- **A.1 Install:** `composer require shirahcan/auth-service-helper:^1.3` plus the npm install line from H2 for the Next side.
- **A.2 Migrations:** `php artisan vendor:publish --tag=auth-service-helper-sharing-config` followed by `php artisan migrate`. Call out by name the three new tables (`outbound_share_messages`, `inbound_share_messages`, `sharing_handoff_log`) so engineers know what's been added to their schema.
- **A.3 Env vars:** Table of required vars with example values:
  - `STUDENDLY_TRUST_KEY` — per-peer secret from auth-service trusted-services UI
  - `STUDENDLY_HMAC_SECRET` — HMAC signing secret (separate from trust key, rotated independently)
  - `STUDENDLY_HMAC_SECRET_PREVIOUS` — optional, set during rotation window
  - `NEXT_PUBLIC_BACKEND_URL`, `NEXT_SERVICE_API_KEY` — from H2 starter app
- **A.4 Listeners:** Show the `EventServiceProvider::$listen` block from H1 §2 (DO NOT re-explain the handler API — link to `docs/Sharing_Usage.md#inbound-events`). Encourage one handler class per `(intent, business_concept)` rather than mega-handlers.
- **A.5 Next handoff:** Direct readers to copy `test-app/app/auth/handoff/route.ts` and `test-app/app/auth/handoff/landing/page.tsx` byte-for-byte. Link the H2 doc. List the exact envvars they need to populate.
- **A.6 Verify:** Spin up both starter apps (PHP + Next), run the Phase G round-trip harness against the destination, confirm an `InboundShareReceived` event fires and a handoff URL lands on `/auth/handoff/landing` and switches accounts.

### Step 3 — Fill in Persona B (greenfield, source + destination)

- **B.1:** "Do everything in Persona A first."
- **B.2 Intent slugs:** Explain the `<source_namespace>.<event_name>`
  convention (spec §6). Recommend a 1-page table mapping product
  business events → intent slugs as a design artifact before any code
  ships. Example for a hypothetical "Coursely" product:
  - `coursely.enrollment_confirmed` → `service_purchase`-shaped
  - `coursely.transcript_updated` → custom intent, register via
    `IntentRegistry::register`
- **B.3 `Sharing::shareUser`:** Show the call site pattern (the spec §7
  example). Critical: the right *trigger point* is the
  business-meaningful relationship establishment, not the first HTTP
  request. For "user paid for X on source, fulfill on destination," the
  trigger is the post-payment webhook, NOT page load.
- **B.4 `Sharing::sendPayload`:** Same call site as B.3 in most cases.
  Stress that `idempotencyKey` MUST be derived from product domain data
  (order id, doc id), NEVER from `uniqid()` / `Str::uuid()` — that
  defeats the dedup purpose.
- **B.5 Handoff tokens:** Show the CTA pattern from spec §7 step 3.
  Stress that tokens are short-lived (~60s), so the mint MUST happen at
  CTA click time, not at email-render time or at page-load time before
  the click.
- **B.6 Round-trip test:** Same as A.6 but with the source side
  exercised too. Recommend using the Phase G1 in-process harness for
  fast feedback loops before promoting to Playwright.

### Step 4 — Fill in Post-launch operations

For each operation, give the exact artisan command + expected output +
recovery action:

- **O.1 Monitor outbox health:** `Sharing::listFailed()` and
  `Sharing::getDeliveryStatus($id)`. Link H1 §4 for full surface.
  Recommend wiring into product's existing alerting (e.g., daily cron
  that pages on `dead_lettered > 0`).
- **O.2 Redeliver from DLQ:** `Sharing::redeliver($messageId)`. Note
  that redelivery resets the retry counter; if the downstream is still
  failing, the message will re-enter the cycle.
- **O.3 Rotate signing secrets:** Two-step rotation:
  1. Set `STUDENDLY_HMAC_SECRET_PREVIOUS` to the current value; set
     `STUDENDLY_HMAC_SECRET` to the new value. Deploy both
     simultaneously to source AND destination.
  2. After the longest expected in-flight envelope TTL has passed (5min
     signature window is the hard ceiling), drop `_PREVIOUS`. Redeploy.
- **O.4 Rotate trust keys:** Same two-step pattern using the
  trusted-services table — see auth-service docs for the UI.

### Step 5 — Fill in Common pitfalls

Bulleted list, each pitfall = 1 sentence diagnosis + 1 sentence fix:

- Mounting `/auth/handoff` under a Next middleware that rewrites
  `?token=` query strings → tokens get stripped, exchange fails. Fix:
  add an explicit exemption in `middleware.ts`.
- Calling `Sharing::sendPayload` from a synchronous request handler
  without ensuring the queue worker is running → outbox row sits in
  `queued` forever. Fix: confirm `queue:work` is alive in the deploy
  topology.
- Using `Str::uuid()` for `idempotencyKey` → every retry creates a new
  domain row at the destination. Fix: derive from product data.
- Forgetting `STUDENDLY_HMAC_SECRET` on the destination → every webhook
  comes back as `400 rejected_signature`. Fix: confirm env var via
  `php artisan tinker` `config('authservice.sharing.signing_secret_env')`.
- Mounting the handoff landing under a route group that requires an
  existing session → the new user gets bounced to `/login` instead of
  landing in the switcher. Fix: landing must be public, the multi-account
  add itself establishes the session.

### Step 6 — Fill in Links section + commit

End the doc with a short Links section pointing to:

- `README.md` (PHP helper root)
- `docs/Sharing_Usage.md` (PHP API reference — H1)
- `auth-service-nextjs/README.md` (Next-side handoff section — H2)
- `auth-service/project/docs/features/user-sharing/` (auth-service-side
  user-sharing docs, already shipped 2026-05-25)
- The plan folder: `docs/plans/InterProductCommunication-2026-05-27/`

```bash
cd C:\Users\benpl\Documents\GitHub\auth-service-helper

test -s docs/MigrationGuide-CrossProductSharing.md && echo OK

git add docs/MigrationGuide-CrossProductSharing.md
git commit -m "feat(sharing): phase H3 — consumer-product migration guide

End-to-end walkthrough for two personas: existing destination-only
product (Portify) and greenfield source+destination product. Covers
install, migrations, envvars, listener registration, Next handoff
wiring, post-launch ops (outbox monitoring, DLQ redelivery, secret
rotation), and common pitfalls. References H1 + H2 for API depth;
this doc is a checklist, not a reference.

Phase: H3 of docs/plans/InterProductCommunication-2026-05-27/"
```

## Note for H4

The operations section in this guide is INTRO-LEVEL. The actual
metrics/dashboards/alerts catalogue lives in H4's `Observability-Sketch.md`
— link to it from this guide's "Post-launch operations" intro paragraph
once H4 lands.
