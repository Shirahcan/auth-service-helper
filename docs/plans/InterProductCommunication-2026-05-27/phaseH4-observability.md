# Phase H4 — Audit / observability sketch

**Repo:** `auth-service-helper` (cross-repo design artifact lives with the helper docs)
**Spec section:** §15 "Out of Scope" — this phase EXPLICITLY does not implement; it lays groundwork for a future "Observability" plan.
**Depends on:** Entire Phase E (outbox states), Phase D (inbox states), Phase A (handoff tokens) — the surfaces being observed must exist before sketching the observation layer.

## Goal

A short, opinionated design sketch (`docs/Observability-Sketch.md`) that
names exactly what metrics, log lines, admin-UI tiles, and artisan
commands a future implementor would need to build to give the cross-product
sharing fabric operational visibility.

**Out of scope for direct implementation in this plan.** This phase
produces ONE markdown document. No code, no migrations, no tests. The
document becomes the spec input for a follow-up plan ("Observability"
or similar) that the team can pick up after the H1–H3 docs and Phase
A–G implementation have been shipped and operated for a quarter or two.

## Files

- **Create:** `docs/Observability-Sketch.md`

## Steps

### Step 1 — Outline the sketch

Top-level structure of `docs/Observability-Sketch.md`:

```
# Cross-Product Sharing — Observability Sketch

> Status: design-only. No code in this plan.
> Successor plan: TBD ("Observability" — owner unassigned).

## Why this sketch exists
## Outbox health
## Inbox health
## Handoff health
## Per-peer dashboards
## Suggested artisan commands
## Suggested log structure
## Suggested admin-UI tiles
## Open questions for the successor plan
```

### Step 2 — Fill in "Why this sketch exists"

Two paragraphs:

1. The Phase A–G implementation gives products the *primitives* (typed
   exceptions, outbox/inbox state machines, handoff token rows). What
   it does NOT give is the operator-facing aggregation: "how healthy
   is the Studendly→Portify pipeline today?" That aggregation needs to
   be designed once, then reused across every (source, destination)
   pair as the product surface grows.
2. This document is a checklist for that future plan. It assumes the
   reader has read the spec (§§ 9, 10, 13) and knows the underlying
   data model. It does NOT prescribe a specific dashboarding tool
   (Grafana, Datadog, Laravel Pulse, internal admin UI) — that choice
   belongs to the successor plan.

### Step 3 — Fill in "Outbox health"

Bulleted list of metrics + 1-line rationale each:

- `sharing.outbox.count_by_status{status="queued|in_flight|retry_scheduled|delivered|dead_lettered|failed_permanent"}` — primary gauge; the shape of the histogram tells you whether delivery is keeping up.
- `sharing.outbox.oldest_queued_age_seconds` — single number; spikes above a few minutes mean the worker is stalled.
- `sharing.outbox.dlq_size` — should be a flat zero; any non-zero is a paging condition.
- `sharing.outbox.retry_attempts` histogram — distribution of attempt counts at the moment of delivery. A long tail means the destination is flaky.
- `sharing.outbox.delivery_latency_seconds` histogram — `delivered_at - queued_at` per message.

Suggested alerting:
- DLQ size > 0 → page on-call.
- Oldest queued > 5 min → warn.
- p95 delivery latency > 30s → warn.

### Step 4 — Fill in "Inbox health"

Bulleted list:

- `sharing.inbox.rejected_signature_total` — counter incremented on every `EnvelopeSignatureMismatchException`. Non-zero on a stable peer means key rotation issue.
- `sharing.inbox.rejected_schema_total{intent="..."}` — counter per intent. Spike on one intent means the source shipped a schema change without coordination.
- `sharing.inbox.duplicate_total{source_service_id="..."}` — counter; healthy peers will show a low baseline (retries due to timeouts). Spike = source's outbox retry math is wrong.
- `sharing.inbox.dispatch_latency_seconds` — `dispatched_at - received_at`. If handlers are not `ShouldQueue`, this is your handler runtime.

### Step 5 — Fill in "Handoff health"

Bulleted list:

- `sharing.handoff.tokens_minted_total{minted_by_service_id="..."}` — counter; baseline traffic.
- `sharing.handoff.tokens_exchanged_total{consumed_by_service_id="..."}` — counter; should track minted (less expired drops).
- `sharing.handoff.replay_attempts_total` — counter from `handoff_tokens.replay_attempts`. **Alert threshold: >3 attempts on a single token in 1h.** That's the spec §13 alert trigger.
- `sharing.handoff.exchange_failures_total{reason="expired|mismatch|unknown|wrong_target"}` — counter per typed failure mode.
- `sharing.handoff.mint_to_exchange_seconds` histogram — measures how long users sit on the source-side CTA before clicking. Useful for tuning the 60s TTL.

### Step 6 — Fill in "Per-peer dashboards"

One paragraph: every metric above SHOULD be group-by-able by
`source_service_id` (for outbox + handoff-mint metrics) or
`target_service_id` (for inbox + handoff-exchange metrics). The
dashboarding tool should expose a "peer pair selector" that lets an
operator pivot from "Portify globally" to "Portify ← Studendly only" in
one click. This is critical: a regression in one peer pair must not be
masked by aggregate health.

### Step 7 — Fill in "Suggested artisan commands"

Table:

| Command | Purpose | Phase H4 status |
|---|---|---|
| `sharing:outbox:status [--peer=slug]` | One-shot human-readable status dump (count by status, oldest queued, DLQ depth) | Not implemented — future work |
| `sharing:inbox:status [--peer=slug]` | Same shape for inbox (rejected counts, duplicate count, dispatch latency p95) | Not implemented — future work |
| `sharing:outbox:redeliver {messageId}` | Wraps `Sharing::redeliver()` for ops use | Wrapper around Phase E5; thin to implement |
| `sharing:outbox:purge-delivered [--older-than-days=30]` | Retention pass on `delivered` rows | Not implemented — future work (also called out in master-index Risks table) |
| `sharing:handoff:tail [--peer=slug]` | Live tail of mint/exchange events for debugging | Not implemented — future work |

### Step 8 — Fill in "Suggested log structure"

JSON-structured log lines, one shape per noteworthy event:

- `sharing.outbox.queued` — `{message_id, share_id, intent, target_service_id, idempotency_key}`
- `sharing.outbox.delivered` — `{message_id, attempts, latency_ms}`
- `sharing.outbox.dead_lettered` — `{message_id, attempts, reason, last_error}`
- `sharing.inbox.received` — `{message_id, source_service_id, intent, idempotency_key}`
- `sharing.inbox.rejected` — `{message_id, reason, source_service_id}` where reason ∈ {`signature`, `schema`, `trust_key`}
- `sharing.handoff.minted` — `{token_id_prefix, share_id, target_service_id, next_path, expires_at}` (NEVER log the full token)
- `sharing.handoff.exchanged` — `{token_id_prefix, consumed_by_service_id, share_id}`
- `sharing.handoff.replay_attempted` — `{token_id_prefix, replay_attempts}` — emit at WARN level when count > 1

### Step 9 — Fill in "Suggested admin-UI tiles"

For a future `/admin/sharing` console:

1. Top-of-page traffic light per peer pair (green/yellow/red based on DLQ size + recent rejection rate).
2. "Outbox queue" table — sortable by status, age, target. Action buttons: redeliver, view payload (redacted).
3. "Inbox rejections" table — last 24h of rejected envelopes with reason and source.
4. "Handoff replay alerts" — any token with `replay_attempts > 3` in the last 24h, with the original mint context.
5. "Schema drift detector" — for each intent, count distinct payload field-sets observed in the last 7 days. A new field-set appearing without a coordinated schema bump is the early signal of an uncoordinated source change.

### Step 10 — Fill in "Open questions for the successor plan"

Numbered list (these are NOT to be answered in H4 — they are the inputs
to whoever writes the successor plan):

1. Single shared dashboard host, or per-product (e.g. each consumer
   product runs its own observability stack and the helper just emits
   metrics)?
2. Storage retention for outbox/inbox rows beyond the 30-day idempotency
   window — does ops want 90 days for compliance? 1 year? Forever for
   the audit trail?
3. PII redaction policy for payload preview in the admin UI — show
   field names only? Show first-N chars? Full visibility behind a
   second auth factor?
4. Schema drift detector accuracy — is "new field-set without bump"
   noisy enough to need a separate review queue, or can it page
   directly?

### Step 11 — Commit

```bash
cd C:\Users\benpl\Documents\GitHub\auth-service-helper

test -s docs/Observability-Sketch.md && echo OK

git add docs/Observability-Sketch.md
git commit -m "feat(sharing): phase H4 — observability sketch (design-only)

Design sketch for a future Observability plan: metrics catalogue
(outbox / inbox / handoff health), per-peer dashboard requirements,
suggested artisan commands, structured-log shapes, admin-UI tile
list, open questions. EXPLICITLY no implementation in this phase —
this document is the spec input for a successor plan.

Phase: H4 of docs/plans/InterProductCommunication-2026-05-27/"
```

## Note

This phase does NOT touch the master index implementation checklist for
A–G; it adds a single docs file. The successor plan referenced
throughout ("TBD — Observability") is intentionally unscheduled — pick
it up when the helper has been in production long enough to know which
metrics actually mattered vs. which were over-engineered guesses.
