<?php

namespace AuthService\Helper\Sharing\Outbox;

use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SharingOutboxRepository
{
    /**
     * Insert (or return existing) outbound message for a given envelope.
     * Idempotent on (peer_slug, idempotency_key).
     */
    public function enqueue(ShareEnvelope $envelope, string $peerSlug): OutboundShareMessage
    {
        $existing = OutboundShareMessage::query()
            ->where('peer_slug', $peerSlug)
            ->where('idempotency_key', $envelope->idempotencyKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        return OutboundShareMessage::create([
            'id' => (string) Str::uuid(),
            'share_id' => $envelope->correlationId,
            'target_service_id' => $envelope->targetServiceId,
            'peer_slug' => $peerSlug,
            'intent' => $envelope->intent,
            'intent_version' => $envelope->intentVersion,
            'idempotency_key' => $envelope->idempotencyKey,
            'envelope_json' => $envelope->toArray(),
            'signature_header' => null,
            'status' => OutboundShareMessage::STATUS_QUEUED,
            'attempts' => 0,
            'next_retry_at' => null,
        ]);
    }

    /**
     * Rows due for delivery: queued (no schedule) OR retry_scheduled with
     * next_retry_at <= now.
     */
    public function findDeliverable(int $limit = 25): Collection
    {
        return OutboundShareMessage::query()
            ->whereIn('status', OutboundShareMessage::DELIVERABLE_STATES)
            ->where(function ($q) {
                $q->whereNull('next_retry_at')
                  ->orWhere('next_retry_at', '<=', Carbon::now());
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->get();
    }

    public function markInFlight(OutboundShareMessage $row): void
    {
        $row->forceFill([
            'status' => OutboundShareMessage::STATUS_IN_FLIGHT,
            'attempts' => $row->attempts + 1,
            'last_attempt_at' => Carbon::now(),
        ])->save();
    }

    public function markDelivered(OutboundShareMessage $row): void
    {
        $row->forceFill([
            'status' => OutboundShareMessage::STATUS_DELIVERED,
            'delivered_at' => Carbon::now(),
            'next_retry_at' => null,
        ])->save();
    }

    public function scheduleRetry(
        OutboundShareMessage $row,
        int $attemptN,
        ?int $responseStatus,
        ?string $error,
        ?int $delaySeconds = null,
    ): void {
        $delaySeconds = $delaySeconds ?? 60;

        $row->forceFill([
            'status' => OutboundShareMessage::STATUS_RETRY_SCHEDULED,
            'next_retry_at' => Carbon::now()->addSeconds($delaySeconds),
            'last_response_status' => $responseStatus,
            'last_error' => $error,
        ])->save();
    }

    public function deadLetter(OutboundShareMessage $row, ?string $error = null): void
    {
        $row->forceFill([
            'status' => OutboundShareMessage::STATUS_DEAD_LETTERED,
            'dead_lettered_at' => Carbon::now(),
            'next_retry_at' => null,
            'last_error' => $error ?? $row->last_error,
        ])->save();
    }

    public function markFailedPermanent(
        OutboundShareMessage $row,
        ?int $responseStatus,
        ?string $error,
    ): void {
        $row->forceFill([
            'status' => OutboundShareMessage::STATUS_FAILED_PERMANENT,
            'last_response_status' => $responseStatus,
            'last_error' => $error,
            'next_retry_at' => null,
        ])->save();
    }

    public function getByShareId(string $shareId): Collection
    {
        return OutboundShareMessage::query()->where('share_id', $shareId)->get();
    }

    public function getByMessageId(string $messageId): ?OutboundShareMessage
    {
        return OutboundShareMessage::query()->find($messageId);
    }
}
