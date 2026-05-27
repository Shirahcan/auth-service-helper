<?php

namespace AuthService\Helper\Sharing;

use AuthService\Helper\Sharing\Client\HandoffMintResult;
use AuthService\Helper\Sharing\Client\HandoffTokenClient;
use AuthService\Helper\Sharing\Client\ShareResult;
use AuthService\Helper\Sharing\Client\UserShareClient;
use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use AuthService\Helper\Sharing\Exceptions\UserShareCollisionException;
use AuthService\Helper\Sharing\Intents\Contracts\SharePayload;
use AuthService\Helper\Sharing\Outbox\Exceptions\RedeliveryNotPermittedException;
use AuthService\Helper\Sharing\Outbox\Jobs\DispatchOutboundShareJob;
use AuthService\Helper\Sharing\Outbox\OutboundShareMessage;
use AuthService\Helper\Sharing\Outbox\SharingOutboxRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * The main facade implementation. Composed via Laravel container; accessed
 * via the static facade alias `\AuthService\Helper\Sharing\Facades\Sharing`.
 *
 * Source-side ergonomic API for the cross-product user-share + handoff
 * protocol. Composes UserShareClient + HandoffTokenClient + (in Phase E)
 * SharingOutboxRepository.
 */
class SharingService
{
    public function __construct(
        protected UserShareClient $userShareClient,
        protected HandoffTokenClient $handoffTokenClient,
        protected SharingOutboxRepository $outbox,
    ) {}

    /**
     * Establish (or look up) a share between the current source service
     * and the named target service for the given user.
     *
     * If a collision exists (target already has a distinct user with the
     * same email), and $strictOnConflict is true, throws
     * UserShareCollisionException; otherwise returns a ShareResult with
     * the conflict info populated.
     */
    public function shareUser(
        string $userId,
        string $targetService,
        ?string $intent = null,
        array $grantedRoles = [],
        array $metadata = [],
        bool $strictOnConflict = false,
    ): ShareResult {
        $targetServiceId = $this->resolveServiceId($targetService);

        $result = $this->userShareClient->shareUser(
            userId: $userId,
            targetServiceId: $targetServiceId,
            intent: $intent,
            grantedRoles: $grantedRoles,
            metadata: $metadata,
        );

        if ($strictOnConflict && $result->conflict !== null) {
            throw UserShareCollisionException::fromConflict(
                $result->conflict,
                resolutionUrl: "/api/v1/auth/user-shares/conflicts/{$result->conflict->id}",
            );
        }

        return $result;
    }

    public function mintHandoffToken(string $shareId, ?string $nextPath = null): HandoffMintResult
    {
        return $this->handoffTokenClient->mint($shareId, $nextPath);
    }

    public function revokeShare(string $shareId, ?string $reason = null): void
    {
        $this->userShareClient->revoke($shareId, $reason);
    }

    public function resolveCollision(string $conflictId, string $strategy, array $params = []): array
    {
        return $this->userShareClient->resolveCollision($conflictId, $strategy, $params);
    }

    public function waitForMergeCompletion(string $conflictId, int $timeoutSeconds = 30): array
    {
        return $this->userShareClient->waitForMergeCompletion($conflictId, $timeoutSeconds);
    }

    // ─── Stubs filled in by later phases ─────────────────────────────────

    public function sendPayload(
        string $shareId,
        string $intent,
        SharePayload $payload,
        string $idempotencyKey,
        ?string $peerSlug = null,
        ?string $targetServiceId = null,
        ?string $userId = null,
        ?string $sourceServiceId = null,
    ): OutboundShareMessage {
        $slug = $peerSlug ?? (string) config('authservice.sharing.default_peer_slug', 'portify');

        $envelope = ShareEnvelope::fromArray([
            'envelope_version' => '1',
            'message_id' => 'msg_' . Str::ulid()->toBase32(),
            'correlation_id' => $shareId,
            'intent' => $intent,
            'intent_version' => $payload::intentVersion(),
            'source_service_id' => $sourceServiceId
                ?? (string) config('authservice.sharing.source_service_id'),
            'target_service_id' => $targetServiceId
                ?? (string) config("authservice.sharing.peers.{$slug}.target_service_id"),
            'user_id' => $userId ?? '',
            'idempotency_key' => $idempotencyKey,
            'issued_at' => now()->toIso8601String(),
            'payload' => $payload->toArray(),
        ]);

        $row = $this->outbox->enqueue($envelope, $slug);

        if ($row->status === OutboundShareMessage::STATUS_QUEUED) {
            DispatchOutboundShareJob::dispatch($row->id);
        }

        return $row;
    }

    /**
     * Re-attempt delivery of a previously DEAD_LETTERED message. Resets
     * attempts so the row gets the full 5-attempt backoff schedule again.
     * Throws if the caller targets a row that isn't safely re-deliverable.
     */
    public function redeliver(string $outboundMessageId): OutboundShareMessage
    {
        $row = $this->outbox->getByMessageId($outboundMessageId);

        if ($row === null) {
            throw new RedeliveryNotPermittedException(
                "Outbound message not found: {$outboundMessageId}",
            );
        }

        if ($row->status !== OutboundShareMessage::STATUS_DEAD_LETTERED) {
            throw new RedeliveryNotPermittedException(
                "Cannot redeliver a row in status '{$row->status}'. "
                . "Only DEAD_LETTERED rows are re-deliverable.",
            );
        }

        $row->forceFill([
            'status' => OutboundShareMessage::STATUS_QUEUED,
            'attempts' => 0,
            'next_retry_at' => null,
            'dead_lettered_at' => null,
            'last_response_status' => null,
            // last_error is intentionally preserved as audit context
        ])->save();

        DispatchOutboundShareJob::dispatch($row->id);

        return $row->fresh();
    }

    /**
     * All terminal-failure rows (dead-lettered + failed-permanent) ordered
     * newest first. Useful for an ops triage UI.
     */
    public function listFailed(): Collection
    {
        return OutboundShareMessage::query()
            ->whereIn('status', [
                OutboundShareMessage::STATUS_DEAD_LETTERED,
                OutboundShareMessage::STATUS_FAILED_PERMANENT,
            ])
            ->orderByDesc('updated_at')
            ->get();
    }

    public function lastInboundFor(string $shareId): ?\AuthService\Helper\Sharing\Inbox\InboundShareMessage
    {
        return \AuthService\Helper\Sharing\Inbox\Queries\Sharing::lastInboundFor($shareId);
    }

    protected function resolveServiceId(string $targetServiceSlugOrUuid): string
    {
        // If it looks like a UUID, return as-is. Else resolve via config.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $targetServiceSlugOrUuid)) {
            return $targetServiceSlugOrUuid;
        }
        $resolved = config("authservice.sharing.peers.{$targetServiceSlugOrUuid}.target_service_id");
        if (!$resolved) {
            throw new \InvalidArgumentException(
                "Unknown target service slug '{$targetServiceSlugOrUuid}'. Set authservice.sharing.peers.{slug}.target_service_id."
            );
        }
        return $resolved;
    }
}
