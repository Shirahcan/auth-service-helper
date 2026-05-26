# Inter-Product Communication — Master Plan Index

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Also read `IMPLEMENTATION_AGENT_PROMPT.md` in this folder before starting.

**Goal:** Build the helper-package + minimal auth-service primitives that let products (Studendly, Portify, …) share users + push rich domain payloads + execute seamless cross-product redirects, over a long-lived multi-month relationship.

**Architecture:** Two-plane design. (1) **Identity plane** — auth-service is the source of truth for `user_shares` (already shipped) plus TWO new endpoints for handoff tokens. (2) **Domain plane** — direct P2P between source and destination products via the helper's signed envelope, idempotency-keyed, queue-backed retry+DLQ. Destination products receive via a helper-mounted webhook controller and dispatch product code via Laravel events. Cross-product redirect uses a single-use ~60s handoff token consumed at destination's `/auth/handoff` route; the user is auto-added to the existing multi-account switcher.

**Tech Stack:** Laravel 11 (auth-service + PHP helper consumers) · PHP 8.2 · `shirahcan/auth-service-helper` (PHP package, extended) · Next.js App Router · `@benbraide/auth-service-nextjs` (extended) · PostgreSQL/MySQL (helper migrations target both) · Redis (queue connection) · Pest (PHP tests) · Playwright (Next E2E)

**Spec:** `docs/superpowers/specs/2026-05-26-inter-product-communication-design.md`

---

## Repos Touched

| Repo | Path | What changes |
|---|---|---|
| `auth-service` | `C:\Users\benpl\Documents\GitHub\auth-service` | 2 new endpoints (handoff-token mint + exchange), 1 migration, 1 model, 1 controller, 1 iframe postMessage handler |
| `auth-service-helper` | `C:\Users\benpl\Documents\GitHub\auth-service-helper` | New `Sharing/` namespace (bulk of work) — envelope, intent registry, outbox/inbox, webhook controller, source-side facade |
| `auth-service-nextjs` | `C:\Users\benpl\Documents\GitHub\auth-service-nextjs` | New `sharing/` module — `consumeHandoffToken`, `/auth/handoff` route, `multiAccountAutoAdd`, new postMessage type |

---

## Phase Sequencing & Dependencies

```
Phase A (auth-service)
   ├─ A1 → A2 → A3 → A4 → A5
   └─ A6 (iframe, independent of A1-A5)

Phase B (helper foundation) — depends on nothing
   ├─ B1 (ServiceProvider scaffolding)
   ├─ B2 (ShareEnvelope) → B3 (Signer) → B4 (Verifier)
   ├─ B5 (IdempotencyGuard)
   ├─ B6 (IntentRegistry + SharePayload contract)
   └─ B7a, B7b, B7c (built-in payloads, parallelizable after B6)

Phase C (helper source-side) — depends on B
   ├─ C1a → C1b → C1c (UserShareClient)
   ├─ C2a → C2b (collision handling) — depends on C1
   ├─ C3 (HandoffTokenClient) — depends on A1-A5
   └─ C4 (Sharing facade) — depends on C1, C2, C3

Phase D (helper inbox) — depends on B
   ├─ D1 (model+migration)
   ├─ D2a → D2b → D2c (webhook controller)
   ├─ D3 (events)
   └─ D4 (routing)

Phase E (helper outbox) — depends on B, C
   ├─ E1 (model+migration)
   ├─ E2 (repository)
   ├─ E3 (dispatch job skeleton)
   ├─ E4 (retry+backoff)
   ├─ E5 (DLQ + redeliver)
   └─ E6 (status surface)

Phase F (Next helper) — depends on A1-A5 (real exchange endpoint)
   ├─ F1 (consumeHandoffToken)
   ├─ F2 (/auth/handoff route)
   ├─ F3 (postMessage type)
   ├─ F4 (multiAccountAutoAdd)
   └─ F5 (HandoffLanding + useHandoffArrival + toast)

Phase G (integration tests) — depends on B, C, D, E, F
   ├─ G1 (PHP 2-app round-trip)
   ├─ G2 (Playwright 2-tab)
   └─ G3 (contract test fixture publishing)

Phase H (docs) — depends on G (after-the-fact docs)
   ├─ H1 (PHP helper README)
   ├─ H2 (Next helper README + starter app)
   ├─ H3 (consumer-product migration guide)
   └─ H4 (audit/observability sketch)
```

---

## Phase Files

### Phase A — Auth-service handoff tokens (in `auth-service` repo)

- [ ] [A1. handoff_tokens migration](phaseA1-handoff-tokens-migration.md)
- [ ] [A2. HandoffToken model + factory](phaseA2-handoff-token-model.md)
- [ ] [A3. HandoffTokenController::mint + tests](phaseA3-mint-endpoint.md)
- [ ] [A4. HandoffTokenController::exchange + tests](phaseA4-exchange-endpoint.md)
- [ ] [A5. Routes + OpenAPI contract fixture](phaseA5-routes-openapi.md)
- [ ] [A6. Iframe ADD_ACCOUNT_WITH_TOKEN handler](phaseA6-iframe-postmessage.md)

### Phase B — PHP helper Sharing/ foundation (in `auth-service-helper` repo)

- [ ] [B1. ServiceProvider + composer wiring](phaseB1-service-provider.md)
- [ ] [B2. ShareEnvelope DTO + validator](phaseB2-share-envelope.md)
- [ ] [B3. EnvelopeSigner (HMAC ±5min)](phaseB3-envelope-signer.md)
- [ ] [B4. EnvelopeVerifier](phaseB4-envelope-verifier.md)
- [ ] [B5. IdempotencyGuard](phaseB5-idempotency-guard.md)
- [ ] [B6. IntentRegistry + SharePayload contract](phaseB6-intent-registry.md)
- [ ] [B7a. Built-in payloads I: ServicePurchase, ProfileSync](phaseB7a-payloads-i.md)
- [ ] [B7b. Built-in payloads II: DocumentAdded, StatusUpdate](phaseB7b-payloads-ii.md)
- [ ] [B7c. Built-in payloads III: Referral, Invite, RevocationNotice](phaseB7c-payloads-iii.md)

### Phase C — PHP helper source-side facade (in `auth-service-helper` repo)

- [ ] [C1a. UserShareClient::shareUser + tests](phaseC1a-usershareclient-shareuser.md)
- [ ] [C1b. UserShareClient list/get + tests](phaseC1b-usershareclient-reads.md)
- [ ] [C1c. UserShareClient revoke + bulkRevoke](phaseC1c-usershareclient-revoke.md)
- [ ] [C2a. UserShareCollisionException + listConflicts/getConflict](phaseC2a-conflict-reads.md)
- [ ] [C2b. resolveCollision + waitForMergeCompletion](phaseC2b-conflict-resolution.md)
- [ ] [C3. HandoffTokenClient (mint + exchange)](phaseC3-handoff-token-client.md)
- [ ] [C4. Sharing facade wiring](phaseC4-sharing-facade.md)

### Phase D — PHP helper inbox (in `auth-service-helper` repo)

- [ ] [D1. InboundShareMessage model + migration](phaseD1-inbound-model.md)
- [ ] [D2a. Webhook signature + trust-key middleware](phaseD2a-webhook-middleware.md)
- [ ] [D2b. Webhook controller: idempotency + persist](phaseD2b-webhook-controller-persist.md)
- [ ] [D2c. Schema validation + event dispatch + InboundHandoffExchangeController](phaseD2c-webhook-validation-dispatch.md)
- [ ] [D3. InboundShareReceived + InboundHandoffCompleted events](phaseD3-inbound-events.md)
- [ ] [D4. ServiceProvider routing registration](phaseD4-routing.md)

### Phase E — PHP helper outbox (in `auth-service-helper` repo)

- [ ] [E1. OutboundShareMessage model + migration + states](phaseE1-outbound-model.md)
- [ ] [E2. SharingOutboxRepository](phaseE2-outbox-repository.md)
- [ ] [E3. DispatchOutboundShareJob skeleton](phaseE3-dispatch-job-skeleton.md)
- [ ] [E4. Retry schedule + exponential backoff + 4xx/5xx classification](phaseE4-retry-logic.md)
- [ ] [E5. Dead-letter handling + Sharing::redeliver](phaseE5-dead-letter.md)
- [ ] [E6. Status surface: lastInboundFor, getDeliveryStatus, listFailed](phaseE6-status-surface.md)

### Phase F — Next helper sharing/ (in `auth-service-nextjs` repo)

- [ ] [F1. consumeHandoffToken (server-side)](phaseF1-consume-handoff-token.md)
- [ ] [F2. /auth/handoff route handler installer](phaseF2-handoff-route.md)
- [ ] [F3. ADD_ACCOUNT_WITH_TOKEN postMessage type](phaseF3-postmessage-type.md)
- [ ] [F4. multiAccountAutoAdd browser primitive](phaseF4-multi-account-auto-add.md)
- [ ] [F5. HandoffLanding + useHandoffArrival + toast](phaseF5-handoff-landing.md)

### Phase G — Integration tests (cross-repo)

- [ ] [G1. PHP 2-app in-process round-trip harness](phaseG1-php-round-trip.md)
- [ ] [G2. Playwright 2-tab E2E harness](phaseG2-playwright-2-tab.md)
- [ ] [G3. Auth-service OpenAPI contract test fixture publishing](phaseG3-contract-fixture.md)

### Phase H — Docs + starter apps (cross-repo)

- [ ] [H1. PHP helper README + Sharing/ usage doc](phaseH1-php-readme.md)
- [ ] [H2. Next helper README + starter app handoff integration](phaseH2-next-readme.md)
- [ ] [H3. Consumer-product migration guide](phaseH3-migration-guide.md)
- [ ] [H4. Audit / observability dashboard sketch](phaseH4-observability.md)

---

## Status Tracker

| Phase | Files | Status | Notes |
|---|---|---|---|
| A | 6 | ⬜ Not started | auth-service work |
| B | 9 | ⬜ Not started | Helper foundation |
| C | 7 | ⬜ Not started | Source-side helper API |
| D | 6 | ⬜ Not started | Destination-side helper webhooks |
| E | 6 | ⬜ Not started | Outbox/queue machinery |
| F | 5 | ⬜ Not started | Next helper |
| G | 3 | ⬜ Not started | Integration tests |
| H | 4 | ⬜ Not started | Docs |

**Total phase files:** 46

---

## Cross-Cutting Constraints (enforced in every phase)

1. **Additive only.** No `migrate:fresh`. New migrations use `Schema::hasColumn`/`hasTable` guards where editing existing tables.
2. **Test-first.** Every phase has at least one failing test before implementation. Run filtered tests only (`pest --filter=...`), never the full suite per the project's BACKEND TESTING rule.
3. **One feature, one commit.** Each phase ends with a focused `git commit` whose message references the phase number.
4. **Repo discipline.** Phase files name the exact target repo. Implementer switches `cd` (or worktree) as needed. Phase headers show which repo the work happens in.
5. **No background subagents.** Use foreground agents only (parallel is fine).
6. **Helper additions live under `Sharing/` namespace exclusively** — no edits to `AuthServiceClient.php`, `TrustedServiceClient.php`, `Models/User.php`, or any existing file outside the new namespace, except for `AuthServiceHelperServiceProvider.php` (one registration block addition).
7. **Branded HTTP errors.** Helper throws typed exceptions (`UserShareCollisionException`, `HandoffTokenInvalidException`, `EnvelopeSignatureMismatchException`, etc.) — never bare `RuntimeException`.

---

## Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Cross-repo refactor coordination | Phase A ships first end-to-end. Helpers gate on A1–A5 being deployed. |
| Auth-service iframe change is frontend, not Laravel | Phase A6 is isolated; its task file is small and self-contained. Auth-service frontend dev needed. |
| `outbound_share_messages` could grow unbounded | Phase E1 includes a `retention_days` config and a Phase H4 sketch for an `outbound:purge-delivered` artisan command (out of scope here, future work). |
| HMAC secret rotation operational toil | Phase B3 supports current + previous secrets; rotation is operator-driven (no automation in scope here). |
| Replay attack on handoff token | Atomic single-use enforcement (UPDATE with `WHERE consumed_at IS NULL`); replay attempts logged and surface to alerting via Phase H4. |

---

## When complete

Mark every phase checkbox above as `[x]` as you complete it. When all 46 are done, the deliverable is:

- PHP helper v1.3.0 with `Sharing/` namespace published to internal Packagist
- Next helper v1.1.0 with `sharing/` module published to internal NPM
- Auth-service has 2 new endpoints + 1 migration deployed
- A worked example in both starter apps (PHP + Next) showing Studendly → Portify round-trip
- A consumer migration guide that a new product can follow to integrate
