<?php

namespace AuthService\Helper\Sharing\Prep\Client;

use AuthService\Helper\Sharing\Envelope\EnvelopeSigner;
use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PrepClient
{
    public function prepare(
        string $peerSlug,
        string $intentSlug,
        string $idempotencyKey,
        array $sourceResource,
        array $studentData,
        array $payload,
        ?string $returnTo,
    ): PrepResult {
        $peer = $this->peerConfig($peerSlug);
        $envelope = $this->envelope(
            peer: $peer,
            intent: 'prep.prepare',
            idempotencyKey: $idempotencyKey,
            userId: (string) ($studentData['external_id'] ?? ''),
            payload: [
                'operation'       => 'prepare',
                'intent_slug'     => $intentSlug,
                'intent_version'  => '1.0',
                'source_resource' => $sourceResource,
                'student_data'    => $studentData,
                'payload'         => $payload,
                'return_to'       => $returnTo,
            ],
        );

        $body = $this->bodyAndHeaders($envelope, $peer);
        $resp = Http::withHeaders($body['headers'])
            ->withBody($body['raw'], 'application/json')
            ->post($this->prepUrl($peer, 'prepare'));

        if (!$resp->ok()) {
            throw new \RuntimeException("prepare failed: HTTP {$resp->status()} — {$resp->body()}");
        }
        return PrepResult::fromArray($resp->json());
    }

    public function promote(
        string $peerSlug,
        string $prepId,
        string $shareId,
        array $triggerProof,
        string $idempotencyKey,
    ): PromoteResultDto {
        $peer = $this->peerConfig($peerSlug);
        $envelope = $this->envelope(
            peer: $peer,
            intent: 'prep.promote',
            idempotencyKey: $idempotencyKey,
            userId: '',
            payload: [
                'operation'     => 'promote',
                'share_id'      => $shareId,
                'trigger_proof' => $triggerProof,
            ],
        );

        $body = $this->bodyAndHeaders($envelope, $peer);
        $resp = Http::withHeaders($body['headers'])
            ->withBody($body['raw'], 'application/json')
            ->post($this->prepUrl($peer, $prepId . '/promote'));

        if ($resp->status() === 409 && ($resp->json('error') ?? null) === 'already_promoted') {
            return PromoteResultDto::fromArray($resp->json());
        }
        if (!$resp->ok()) {
            throw new \RuntimeException("promote failed: HTTP {$resp->status()} — {$resp->body()}");
        }
        return PromoteResultDto::fromArray($resp->json());
    }

    public function prepStatus(string $peerSlug, string $prepId): PrepStatus
    {
        $peer = $this->peerConfig($peerSlug);
        $envelope = $this->envelope(
            peer: $peer,
            intent: 'prep.status',
            idempotencyKey: 'status:' . $prepId,
            userId: '',
            payload: ['operation' => 'status'],
        );

        $body = $this->bodyAndHeaders($envelope, $peer);
        $resp = Http::withHeaders($body['headers'])
            ->withBody($body['raw'], 'application/json')
            ->post($this->prepUrl($peer, $prepId . '/status'));

        if (!$resp->ok()) {
            throw new \RuntimeException("prepStatus failed: HTTP {$resp->status()} — {$resp->body()}");
        }
        return PrepStatus::fromArray($resp->json());
    }

    protected function peerConfig(string $slug): array
    {
        $peer = (array) config("authservice.sharing.peers.{$slug}", []);
        if (empty($peer)) {
            throw new \InvalidArgumentException("Unknown peer '{$slug}' in authservice.sharing.peers.*");
        }
        return $peer;
    }

    protected function envelope(array $peer, string $intent, string $idempotencyKey, string $userId, array $payload): ShareEnvelope
    {
        return ShareEnvelope::fromArray([
            'envelope_version' => '1',
            'message_id'       => 'msg_' . Str::ulid()->toBase32(),
            'correlation_id'   => $idempotencyKey,
            'intent'           => $intent,
            'intent_version'   => '1.0',
            'source_service_id'=> (string) config('authservice.sharing.source_service_id', ''),
            'target_service_id'=> (string) ($peer['target_service_id'] ?? ''),
            'user_id'          => $userId,
            'idempotency_key'  => $idempotencyKey,
            'issued_at'        => now()->toIso8601String(),
            'payload'          => $payload,
        ]);
    }

    /** @return array{raw:string, headers:array<string,string>} */
    protected function bodyAndHeaders(ShareEnvelope $envelope, array $peer): array
    {
        $raw = $envelope->toCanonicalJson();
        $signature = (new EnvelopeSigner((string) $peer['signing_secret']))->sign($envelope);
        return [
            'raw' => $raw,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Signature'  => $signature,
                'X-Trust-Key'  => (string) ($peer['trust_key'] ?? ''),
                'X-Idempotency-Key' => $envelope->idempotencyKey,
            ],
        ];
    }

    protected function prepUrl(array $peer, string $suffix): string
    {
        $base = $peer['prep_base_url'] ?? null;
        if (!$base) {
            // Derive from webhook_url: drop /api/v1/inbound/user-share, add /api/v1/sharing/prep
            $webhook = (string) ($peer['webhook_url'] ?? '');
            $parsed = parse_url($webhook);
            $base = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'localhost')
                . (isset($parsed['port']) ? ':' . $parsed['port'] : '')
                . '/api/v1/sharing/prep';
        }
        return rtrim($base, '/') . '/' . ltrim($suffix, '/');
    }
}
