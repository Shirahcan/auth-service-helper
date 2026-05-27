# Prep–Sign–Promote — Master Plan Index

> Builds on v1.3 Sharing (master plan: `docs/plans/InterProductCommunication-2026-05-27/00_MASTER_INDEX.md`). Read v1.3 spec + the PSP spec (`docs/superpowers/specs/2026-05-27-prep-sign-promote-pattern.md`) before executing.

**Goal:** ship a two-phase commit primitive for cross-product resource provisioning. Peer creates TEMP record on `/prepare` → user interacts via iframe → `/submit` captures signed data → orchestrator confirms external condition (payment) → `/promote` flips temp to permanent. Abandoned flows GC away cleanly. User-share creation on auth-service is the post-payment trigger that gates `/promote`.

**Tech delta:** Adds `Sharing/Prep/` subnamespace to `auth-service-helper`, new `prep_resources` table, new HTTP routes auto-mounted by SharingServiceProvider, new built-in `agreement_sign` intent handler, new `Sharing::prepare/promote/prepStatus` facade methods, new `sharing:gc-prep` artisan command. `auth-service-nextjs` gets a `<PrepEmbed>` React component + new postMessage types for the iframe handshake.

**Spec:** `docs/superpowers/specs/2026-05-27-prep-sign-promote-pattern.md`

**No auth-service changes.** The existing `user_shares` machinery is sufficient; the change is operational (consumers call `Sharing::shareUser` post-payment, just before `Sharing::promote`).

---

## Repos Touched

| Repo | Path | What changes |
|---|---|---|
| `auth-service-helper` | `C:\Users\benpl\Documents\GitHub\auth-service-helper` | New `Sharing/Prep/` namespace (model, repo, events, controllers, intent registry, GC command, client) + 1 migration + route mounting in SharingServiceProvider |
| `auth-service-nextjs` | `C:\Users\benpl\Documents\GitHub\auth-service-nextjs` | New `sharing/PrepEmbed.tsx` + iframe postMessage types |
| `auth-service` | — | Untouched |

---

## Phase Sequencing & Dependencies

```
Phase B (foundation) — depends on v1.3
   ├─ B1 (model + migration + factory)
   ├─ B2 (repository) — depends on B1
   └─ B3 (events) — depends on B1

Phase C (peer-side HTTP) — depends on B
   ├─ C1 (/prepare)
   ├─ C2 (/embed render + submit) — independent, can parallelize
   └─ C3 (/promote + /status) — depends on B + UserShareClient (v1.3)

Phase D (intent registry) — depends on B
   └─ D1 (registry + handler interface + AgreementSign builtin)

Phase E (orchestrator client) — depends on C
   └─ E1 (PrepClient + facade additions)

Phase F (Next iframe primitives) — independent of B/C/D/E
   └─ F1 (postMessage types + PrepEmbed component)

Phase G (ops) — depends on B + service-provider
   └─ G1 (GC command + route mounting + config)

Phase H (test + docs + ship) — depends on everything
   └─ H1 (integration test + docs/sharing-prep.md + version bump + push + npm publish)
```

---

## Phase Files

- [x] [A. Spec](../../superpowers/specs/2026-05-27-prep-sign-promote-pattern.md) — ✅ `0dec8f3`
- [x] [B1. PrepResource model + migration + factory](phaseB1-prep-resource-model.md) — ✅ `84ea327`
- [x] [B2. PrepResourceRepository](phaseB2-prep-resource-repository.md) — ✅ `84ea327`
- [x] [B3. Prep lifecycle events](phaseB3-prep-resource-events.md) — ✅ `84ea327`
- [x] [C1. /prepare endpoint](phaseC1-prepare-endpoint.md) — ✅ `84ea327`
- [x] [C2. /embed render + /submit endpoints](phaseC2-embed-endpoints.md) — ✅ `84ea327`
- [x] [C3. /promote + /status endpoints](phaseC3-promote-status-endpoints.md) — ✅ `84ea327`
- [x] [D1. PrepIntentRegistry + AgreementSign builtin](phaseD1-intent-registry-agreement-sign.md) — ✅ `84ea327`
- [x] [E1. PrepClient + facade](phaseE1-prep-client-facade.md) — ✅ `84ea327`
- [x] [F1. Next iframe + PrepEmbed component](phaseF1-next-iframe-prep-embed.md) — ✅ `65c9acd` (auth-service-nextjs)
- [x] [G1. GC command + service-provider mount + config](phaseG1-gc-routing-config.md) — ✅ `84ea327`
- [x] [H1. Integration test + docs + ship](phaseH1-integration-test-docs-ship.md) — ✅ `12667b1`

---

## Status Tracker

| Phase | Files | Status | Notes |
|---|---|---|---|
| A | spec | ✅ Done | `0dec8f3` — `docs/superpowers/specs/2026-05-27-prep-sign-promote-pattern.md` |
| B | 3 | ✅ Done | `84ea327` — 12 unit tests green |
| C | 3 | ✅ Done | `84ea327` — 17 feature tests green |
| D | 1 | ✅ Done | `84ea327` — registry + AgreementSign builtin, 8 unit tests |
| E | 1 | ✅ Done | `84ea327` — Sharing::prepare/promote/prepStatus on facade |
| F | 1 | ✅ Done | `65c9acd` — auth-service-nextjs, 10 vitest tests green |
| G | 1 | ✅ Done | `84ea327` — sharing:gc-prep + auto-mounted routes + config |
| H | 1 | ✅ Done | `12667b1` — round-trip integration test (12 assertions), docs/sharing-prep.md, README cross-link, composer 1.4.0 |

**Total phase files:** 12 (11 implementation + spec)

---

## Cross-Cutting Constraints

1. **Additive only.** No `migrate:fresh`; new migration with explicit guards. No edits to existing v1.3 tables.
2. **Test-first.** Every implementation phase ships at least one passing test before the next phase starts.
3. **Reuses v1.3 envelope HMAC + middleware.** `/prepare` and `/promote` are authed by `VerifyShareEnvelopeSignature`. Do NOT introduce a parallel trust scheme.
4. **Per-checkout idempotency.** `idempotency_key` is `(source_service_id, opaque_per_checkout_token)`, never just student id. Same key returns same prep_id — never duplicates a row.
5. **`promoted` rows are immortal.** GC only touches `prepared|signed|expired`. The intent registry's `promote` handler is responsible for any further state machine on the permanent record.
6. **`share_id` is the security boundary on promote.** Without a valid auth-service-issued share, `/promote` rejects. This is what defers user-share creation to post-payment.
7. **One feature, one commit.** Each phase ends with a focused `git commit`.

---

## When complete

Mark every checkbox above as `[x]`. Deliverables:

- PHP helper v1.4.0 with `Sharing/Prep/` published to internal Packagist (via GitHub tag)
- Next helper v1.4.0 with `sharing/PrepEmbed` published to npm
- `docs/sharing-prep.md` cross-linked from the helper README + the consumer migration guide
- One worked example (agreement_sign intent) covered end-to-end by an integration test
