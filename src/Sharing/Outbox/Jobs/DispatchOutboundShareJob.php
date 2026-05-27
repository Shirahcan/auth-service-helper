<?php

namespace AuthService\Helper\Sharing\Outbox\Jobs;

use AuthService\Helper\Sharing\Envelope\EnvelopeSigner;
use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use AuthService\Helper\Sharing\Outbox\OutboundShareMessage;
use AuthService\Helper\Sharing\Outbox\SharingOutboxRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

class DispatchOutboundShareJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $outboundMessageId) {}

    public function handle(SharingOutboxRepository $repo = null): void
    {
        $repo = $repo ?? app(SharingOutboxRepository::class);

        $row = $repo->getByMessageId($this->outboundMessageId);
        if ($row === null) {
            return;
        }
        if ($row->isInTerminalState()) {
            return;
        }

        $peer = (array) config("authservice.sharing.peers.{$row->peer_slug}", []);
        if (empty($peer['webhook_url']) || empty($peer['signing_secret'])) {
            $repo->markFailedPermanent($row, null, "Peer config missing for {$row->peer_slug}");
            return;
        }

        $envelope = ShareEnvelope::fromArray($row->envelope_json);
        $signer = new EnvelopeSigner((string) $peer['signing_secret']);
        $signatureHeader = $signer->sign($envelope);

        $row->forceFill(['signature_header' => $signatureHeader])->save();
        $repo->markInFlight($row);

        $headers = [
            'Content-Type' => 'application/json',
            'X-Signature' => $signatureHeader,
            'X-Trust-Key' => (string) ($peer['trust_key'] ?? ''),
            'X-Idempotency-Key' => $row->idempotency_key,
        ];

        try {
            $response = Http::withHeaders($headers)
                ->timeout((int) ($peer['timeout'] ?? 10))
                ->withBody($envelope->toCanonicalJson(), 'application/json')
                ->send('POST', (string) $peer['webhook_url']);
        } catch (Throwable $e) {
            $this->handleFailure($repo, $row, null, $e->getMessage());
            return;
        }

        $status = $response->status();
        if ($status >= 200 && $status < 300) {
            $repo->markDelivered($row);
            return;
        }

        $this->handleFailure($repo, $row, $status, $response->body());
    }

    /**
     * Skeleton retry path — Phase E4 replaces this with a classifier +
     * exponential backoff + DLQ-after-N-attempts.
     */
    protected function handleFailure(
        SharingOutboxRepository $repo,
        OutboundShareMessage $row,
        ?int $status,
        ?string $error,
    ): void {
        $repo->scheduleRetry($row, attemptN: $row->attempts, responseStatus: $status, error: $error);
    }
}
