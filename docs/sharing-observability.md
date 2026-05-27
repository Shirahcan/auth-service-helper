# Sharing — audit / observability sketch

Concrete recommendations for operating the Sharing protocol in production. These are sketches the operator team builds on top of, not code shipped in v1.3.0.

## Source-of-truth tables

| Table | Owns |
|---|---|
| `outbound_share_messages` | Every payload this product has tried to send to a peer. Keep ~30 days of DELIVERED, full history of DEAD_LETTERED + FAILED_PERMANENT. |
| `inbound_share_messages` | Every envelope a peer has POSTed to us — including duplicates and rejects. The `UNIQUE(source_service_id, idempotency_key)` is what makes the idempotency guard cheap. |

Both tables are append-only from the protocol's POV; status transitions are the only mutations.

## Dashboards (Grafana / equivalent)

Minimum 6 panels per environment:

1. **Outbound queue depth** — `COUNT(*) WHERE status IN ('queued','retry_scheduled') GROUP BY peer_slug`
2. **Outbound delivery rate** — `COUNT(*) FILTER (WHERE status='delivered') / total` over 1h windows per peer
3. **Outbound DLQ + permanent-fail rate** — alert when > 0.5% of recent attempts
4. **Inbound throughput** — `COUNT(*) GROUP BY processing_status` over 1m windows
5. **Inbound rejection breakdown** — pie chart of `rejected_signature`, `rejected_schema`, `duplicate` over 24h
6. **Retry backoff health** — histogram of `attempts` for rows currently in `retry_scheduled`

## Alerts

| Condition | Severity |
|---|---|
| Any peer's DLQ count grows by ≥ 10 in 15m | page |
| Any peer's inbound `rejected_signature` rate > 5% over 5m | page (probable HMAC desync) |
| Outbox row in `in_flight` for > 5m (job stuck?) | warn |
| `outbound_share_messages` table size > N (configurable per env) | warn (run `sharing:purge-delivered`) |
| Auth-service handoff exchange 5xx rate > 1% | page |

## Tracing

Each envelope's `correlation_id` is the same `share_id` end-to-end. Plumb it through:

- Source: tag the dispatched job with `Log::withContext(['share_id' => $row->share_id])`
- Destination: the inbound webhook controller could `Log::withContext(['share_id' => $env->correlationId])` before dispatching `InboundShareReceived`
- Both sides forward to a single Loki/Tempo/Honeycomb sink keyed by `share_id`

A single trace ID across source + destination + auth-service gives you a 1-click view of a slow or stuck share.

## Audit log

Beyond the row history, capture deltas to a dedicated audit channel:

- Every `Sharing::shareUser` call (who, when, target, intent, granted_roles)
- Every `Sharing::sendPayload` (share_id, intent, idempotency_key, peer)
- Every status transition on either table (with `actor_kind = 'system'|'operator'`)
- Every `Sharing::redeliver` (operator + reason)

The audit channel is what lets you answer "why does this customer have access to that data" in a compliance review.

## Sampling vs full capture

Storage estimate for `outbound_share_messages` with envelope_json:

| Tier | Volume | Storage / month (JSON ~2 KB) |
|---|---|---|
| Low (~ 100 shares/day) | 3,000 rows / mo | < 10 MB |
| Medium (~ 1,000 shares/day) | 30,000 / mo | ~ 60 MB |
| High (~ 10,000 shares/day) | 300,000 / mo | ~ 600 MB |

Run `php artisan sharing:purge-delivered` weekly (default 30-day cutoff) to cap DELIVERED rows. Keep DEAD_LETTERED + FAILED_PERMANENT forever (or until explicitly archived) — they're rare and forensic.

## Idempotency key conventions

Pick a key that's unique per **business event**, not per send attempt:

| Good | Bad |
|---|---|
| `studendly:order:{order_uuid}` | `studendly:order:{order_uuid}:{retry_count}` |
| `studendly:document:{doc_uuid}:added` | `Str::uuid()` (fresh on every call → not actually idempotent) |
| `portify:invoice:{invoice_id}:paid` | `now()->timestamp` |

The destination uses this key to dedupe. A repeated `sendPayload()` with the same key produces ONE outbound row, ONE delivery, ONE inbound row, ONE event.

## When secrets leak

If a peer's HMAC secret is compromised:

1. Generate a new secret on both sides
2. Set `current_secret = <new>`, `previous_secret = <old>` on the destination
3. Update `signing_secret = <new>` on the source
4. Deploy both
5. Once flushed (next deploy or operator action), drop `previous_secret`

Until step 5 lands, both old and new signatures verify — buys you a rolling window without dropping in-flight payloads.

## Future work (out of scope for v1.3.0)

- `sharing:status` artisan command — JSON dump of per-peer state for healthchecks
- `sharing:replay-from --since=<ts>` — bulk re-queue DLQ rows that died during a partial outage
- Prometheus metrics exporter (queue depth, retry rate, etc.)
- Schema versioning negotiation (currently every envelope carries `intent_version` but the destination doesn't actively pick a handler per-version — a future `IntentRegistry::register('intent', PayloadClass, version: '2.0', schemaPath: ...)` could route by version)

## References

- Spec: `docs/superpowers/specs/2026-05-26-inter-product-communication-design.md`
- Source-side ops API: `docs/sharing.md`
- Consumer migration: `docs/sharing-consumer-migration.md`
