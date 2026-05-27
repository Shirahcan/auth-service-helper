<?php

namespace AuthService\Helper\Sharing\Envelope;

use AuthService\Helper\Sharing\Envelope\Exceptions\InvalidEnvelopeException;

final class ShareEnvelope
{
    public const SUPPORTED_VERSIONS = ['1'];

    private const REQUIRED_FIELDS = [
        'envelope_version', 'message_id', 'correlation_id',
        'intent', 'intent_version',
        'source_service_id', 'target_service_id', 'user_id',
        'idempotency_key', 'issued_at', 'payload',
    ];

    private function __construct(
        public readonly string $envelopeVersion,
        public readonly string $messageId,
        public readonly string $correlationId,
        public readonly string $intent,
        public readonly string $intentVersion,
        public readonly string $sourceServiceId,
        public readonly string $targetServiceId,
        public readonly string $userId,
        public readonly string $idempotencyKey,
        public readonly string $issuedAt,
        public readonly array $payload,
    ) {}

    public static function fromArray(array $raw): self
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $raw)) {
                throw new InvalidEnvelopeException("Missing required field: {$field}");
            }
        }
        if (!in_array($raw['envelope_version'], self::SUPPORTED_VERSIONS, true)) {
            throw new InvalidEnvelopeException("Unsupported envelope_version: {$raw['envelope_version']}");
        }
        if (!is_array($raw['payload'])) {
            throw new InvalidEnvelopeException('payload must be an object');
        }

        return new self(
            envelopeVersion: $raw['envelope_version'],
            messageId: $raw['message_id'],
            correlationId: $raw['correlation_id'],
            intent: $raw['intent'],
            intentVersion: $raw['intent_version'],
            sourceServiceId: $raw['source_service_id'],
            targetServiceId: $raw['target_service_id'],
            userId: $raw['user_id'],
            idempotencyKey: $raw['idempotency_key'],
            issuedAt: $raw['issued_at'],
            payload: $raw['payload'],
        );
    }

    public function toArray(): array
    {
        return [
            'envelope_version' => $this->envelopeVersion,
            'message_id' => $this->messageId,
            'correlation_id' => $this->correlationId,
            'intent' => $this->intent,
            'intent_version' => $this->intentVersion,
            'source_service_id' => $this->sourceServiceId,
            'target_service_id' => $this->targetServiceId,
            'user_id' => $this->userId,
            'idempotency_key' => $this->idempotencyKey,
            'issued_at' => $this->issuedAt,
            'payload' => $this->payload,
        ];
    }

    /**
     * Canonical JSON for signing — keys in fixed order, JSON_UNESCAPED_SLASHES.
     * Both sides MUST produce byte-identical output for the same envelope.
     */
    public function toCanonicalJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
