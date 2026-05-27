<?php

namespace AuthService\Helper\Sharing\Inbox\Http\Controllers;

use AuthService\Helper\Sharing\Envelope\Exceptions\InvalidEnvelopeException;
use AuthService\Helper\Sharing\Envelope\IdempotencyGuard;
use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use AuthService\Helper\Sharing\Inbox\InboundShareMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InboundShareWebhookController
{
    public function __construct(
        protected IdempotencyGuard $idempotency,
    ) {}

    public function receive(Request $request): JsonResponse
    {
        // 1. Parse envelope
        try {
            $raw = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($raw)) {
                throw new InvalidEnvelopeException('Envelope body must be a JSON object');
            }
            $env = ShareEnvelope::fromArray($raw);
        } catch (\JsonException | InvalidEnvelopeException $e) {
            return $this->error(400, 'invalid_envelope', $e->getMessage());
        }

        $sourceServiceId = (string) $request->attributes->get('source_service_id');
        $signatureHeader = (string) $request->header('X-Signature', '');

        // 2. Idempotency check — UNIQUE(source_service_id, idempotency_key)
        if ($this->idempotency->hasSeen($sourceServiceId, $env->idempotencyKey)) {
            return new JsonResponse([
                'message_id' => $env->messageId,
                'already_processed' => true,
            ], 200);
        }

        // 3. Persist with status=received
        try {
            $row = InboundShareMessage::create([
                'id' => (string) Str::uuid(),
                'envelope_version' => $env->envelopeVersion,
                'message_id' => $env->messageId,
                'correlation_id' => $env->correlationId,
                'intent' => $env->intent,
                'intent_version' => $env->intentVersion,
                'source_service_id' => $sourceServiceId,
                'target_service_id' => $env->targetServiceId,
                'user_id' => $env->userId,
                'idempotency_key' => $env->idempotencyKey,
                'issued_at' => $env->issuedAt,
                'payload' => $env->payload,
                'signature_header' => $signatureHeader,
                'headers' => $this->captureRelevantHeaders($request),
                'received_at' => now(),
                'processing_status' => 'received',
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Race: a parallel request inserted the row between our hasSeen() and now.
            // Treat as duplicate — same outcome as the fast path.
            return new JsonResponse([
                'message_id' => $env->messageId,
                'already_processed' => true,
            ], 200);
        }

        // Validation + event dispatch happen in D2c (subclass/override extends this).
        return new JsonResponse([
            'message_id' => $row->message_id,
            'status' => 'received',
        ], 202);
    }

    /**
     * @return array<string, string|array<int, string>>
     */
    protected function captureRelevantHeaders(Request $request): array
    {
        return collect($request->headers->all())
            ->only([
                'x-trust-key',
                'x-signature',
                'content-type',
                'user-agent',
            ])
            ->all();
    }

    protected function error(int $status, string $reason, string $message): JsonResponse
    {
        return new JsonResponse([
            'error' => $reason,
            'message' => $message,
        ], $status);
    }
}
