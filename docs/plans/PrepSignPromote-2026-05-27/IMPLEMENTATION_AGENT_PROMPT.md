# Implementation Agent Prompt — Prep–Sign–Promote (v1.4)

## You are picking this plan up from scratch

You have **zero context**. Read these BEFORE writing any code:

1. `docs/superpowers/specs/2026-05-27-prep-sign-promote-pattern.md` — the spec. Source of truth.
2. `docs/superpowers/specs/2026-05-26-inter-product-communication-design.md` — v1.3 background. PSP builds on this protocol.
3. `docs/plans/PrepSignPromote-2026-05-27/00_MASTER_INDEX.md` — phase order.
4. The phase file you're working on.

If a phase file's code conflicts with your reading of the spec, **the phase file wins** for that phase. Escalate to the user if the conflict looks substantive.

## Required execution discipline

This plan spans TWO repos:

| When phase header says | cd to |
|---|---|
| `Repo: auth-service-helper` | `C:\Users\benpl\Documents\GitHub\auth-service-helper` |
| `Repo: auth-service-nextjs` | `C:\Users\benpl\Documents\GitHub\auth-service-nextjs` |

Never cross-commit. Each repo has its own history + tag (v1.4.0).

## Mandatory verification trio per phase

For phases B–G:

- **Orchestrator** = you, executing the phase
- **Tester** = a dedicated sub-agent that walks the Studendly → Portify agreement-sign scenario through the just-shipped phase, end-to-end, looking for real-world gaps. Tester does NOT trust unit tests alone.
- **Fixer** = a sub-agent that closes any gap the tester surfaces.

After your unit tests pass, dispatch the tester with: "Walk a real Studendly student through phase {N} of Prep–Sign–Promote, signing Portify's visa-rep agreement. Find every gap." Re-test after fixes. Only then commit.

For Phase B (model/repo/events) the scenario is data integrity (idempotency, expiry math). For Phase C (HTTP) it's request/response shape + middleware + edge cases (expired prep, duplicate idempotency_key, missing share_id). For Phase D the intent handler interface and AgreementSignHandler defaults. For Phase E the client round-trips. For Phase F the iframe ↔ host postMessage handshake under abandonment + reload. For Phase G the GC sweep + cron.

## Test commands (filtered, NEVER full suite)

```bash
# Helper unit/feature tests
cd C:\Users\benpl\Documents\GitHub\auth-service-helper
vendor/bin/phpunit tests/Unit/Sharing/Prep/PrepResourceTest.php
vendor/bin/phpunit tests/Feature/Sharing/Prep/PrepareControllerTest.php
vendor/bin/phpunit tests/Integration/Sharing/PrepSignPromoteRoundTripTest.php   # Phase H1

# Helper full suite (only at end, only filtered to Sharing)
vendor/bin/phpunit --filter=Sharing

# Next helper
cd C:\Users\benpl\Documents\GitHub\auth-service-nextjs
npx vitest run tests/unit/sharing/PrepEmbed.test.tsx
```

Per project rules: never run `vendor/bin/phpunit` without a filter. RefreshDatabase makes each test class 30–90s.

## Commit message format per phase

```
feat(sharing): phase {LETTER}{NUMBER} — {short description}

{2-3 sentence body — what shipped, why it matters}

Phase: {LETTER}{NUMBER} of docs/plans/PrepSignPromote-2026-05-27/
Spec: docs/superpowers/specs/2026-05-27-prep-sign-promote-pattern.md
```

## Strict rules

- **Reuse v1.3 envelope HMAC machinery.** `/prepare`, `/promote`, `/status` are authed by `VerifyShareEnvelopeSignature`. The embed render + submit routes are public, secured by the unguessable `prep_id`.
- **`promoted` rows are immortal.** GC only sweeps `prepared|signed|expired`.
- **`share_id` is the post-payment gate.** `/promote` rejects without it.
- **No background subagents.** Foreground only; parallel sub-agents are fine.
- **No bypassing hooks** (`--no-verify`, etc).
- **No edits to existing v1.3 files** except `SharingServiceProvider::boot()` (adding new route registrations) and `SharingService.php` (adding `prepare/promote/prepStatus` methods).

## When you hit an unknown

If a phase file omits a critical detail (a config key, a response shape, a column name), **do NOT guess**. Surface the question. The phase file is supposed to be self-sufficient.

## When Phase H1 lands

1. Run `vendor/bin/phpunit --filter=Prep` — must be 100% green
2. Run the round-trip integration test
3. Bump `composer.json` version to `1.4.0`, tag `v1.4.0`, push
4. Bump `package.json` version to `1.4.0`, tag `v1.4.0`, push, `npm publish --access public`
5. Update `00_MASTER_INDEX.md` Status Tracker to all `✅ Done`
6. Surface to user: "PSP v1.4.0 shipped. PHP helper v1.4.0 on GitHub; Next helper @benbraide/auth-service-nextjs@1.4.0 on npm."

No PRs to merge — single-trunk workflow, commit directly to `main` (per user's standing instruction `feedback_always_work_on_main`).
