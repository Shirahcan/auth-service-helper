# Implementation Agent Prompt — Inter-Product Communication

## You are picking this plan up from scratch

You have **zero context** for the conversation that produced this plan. Read these files BEFORE writing any code:

1. `docs/superpowers/specs/2026-05-26-inter-product-communication-design.md` — the design spec (~600 lines, 25-min read). This is the source of truth.
2. `docs/plans/InterProductCommunication-2026-05-27/00_MASTER_INDEX.md` — the plan index. Tells you which phase files to execute and in what order.
3. The phase file you're working on. It contains EXACT code, EXACT file paths, EXACT commands.

If a phase file's code conflicts with your interpretation of the spec, the **phase file wins** for that phase. If you think the phase file is wrong, escalate to the user, do NOT silently deviate.

## Required execution discipline

This plan spans THREE repos. You will need to switch between them:

| When phase header says | cd to |
|---|---|
| `Repo: auth-service` | `C:\Users\benpl\Documents\GitHub\auth-service` |
| `Repo: auth-service-helper` | `C:\Users\benpl\Documents\GitHub\auth-service-helper` |
| `Repo: auth-service-nextjs` | `C:\Users\benpl\Documents\GitHub\auth-service-nextjs` |

Each repo has its own git history. **Commit at the end of each phase to the correct repo.** Never cross-commit.

## Mandatory orchestrator/tester/fixer trio

For phases that produce shippable code (everything except H), set up this three-agent loop:

- **Orchestrator** = you, the implementer following the phase file
- **Tester** = a dedicated sub-agent that exercises the change against the real-world scenario described in the spec's "driving use case" (Studendly → Portify months-long admission). Tester does NOT trust the unit tests alone.
- **Fixer** = a sub-agent that closes the loop when the tester surfaces a real-world gap that unit tests missed.

Concretely: after each phase's unit tests pass, dispatch the tester with a prompt like "Walk the Studendly → Portify journey through phase {N} as a real consultant onboarding a paying student. Find every gap." If the tester finds gaps, dispatch the fixer to close them. Re-test. Only then commit.

For Phase A, the "real-world scenario" is the destination-side `/auth/handoff` exchange returning a valid session for a freshly minted token in <100ms p95.

For Phase B/C, it's a PHP feature test that walks `shareUser → sendPayload → revokeShare` end-to-end with `Http::fake()` for auth-service.

For Phase D, it's the webhook controller correctly rejecting (signature, schema) and correctly accepting (idempotency dedupe, event dispatch).

For Phase E, it's the retry job actually retrying on a transient 503 and DLQ-ing on a permanent 422.

For Phase F, it's the Playwright 2-tab harness that opens Studendly → clicks CTA → arrives logged in on Portify.

## Test commands (filtered, not full suite)

Per the project's BACKEND TESTING rule, **NEVER run `php artisan test` with no filter**. Always:

```bash
# auth-service or auth-service-helper consumer tests
php artisan test --filter=HandoffTokenControllerTest
php artisan test tests/Unit/Sharing/EnvelopeSignerTest.php
```

For helper package tests (the package's own suite, not a consumer):

```bash
cd C:\Users\benpl\Documents\GitHub\auth-service-helper
vendor/bin/pest tests/Unit/Sharing/EnvelopeSignerTest.php
```

For Next helper:

```bash
cd C:\Users\benpl\Documents\GitHub\auth-service-nextjs
npm test -- --testPathPattern=consumeHandoffToken
```

## Commit message format per phase

```
feat(sharing): phase {LETTER}{NUMBER} — {short description}

{2-3 sentence body explaining what shipped and why}

Phase: {LETTER}{NUMBER} of docs/plans/InterProductCommunication-2026-05-27/
Spec: docs/superpowers/specs/2026-05-26-inter-product-communication-design.md
```

Example: `feat(sharing): phase B3 — EnvelopeSigner with ±5min window`.

## Strict additive rules

- **No `migrate:fresh`.** Production data exists in consumer apps. Migrations must be additive.
- **Helper package: no edits to existing files** except for `AuthServiceHelperServiceProvider.php` (one registration block). All new code lives under `src/Sharing/`.
- **No background subagents.** Foreground only; parallel is fine.
- **No bypassing hooks** (no `--no-verify`, no `--no-gpg-sign`).

## When you hit an unknown

If a phase file is missing a critical detail (e.g., what the `service_id` should be in a test fixture), do NOT guess. Surface to the user with the specific question. Phase files are written to be self-sufficient; if one isn't, that's a plan bug worth flagging.

## When all 46 phases are done

1. Run the full Phase G integration suite — must be 100% green
2. Update `00_MASTER_INDEX.md` Status Tracker to all `✅ Done`
3. Open one PR per repo (3 PRs total) and cross-link them
4. Surface to user: "All 46 phases complete. PRs: <links>. Ready for human review."

Do not merge any PR yourself.
