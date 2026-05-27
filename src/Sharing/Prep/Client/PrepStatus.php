<?php

namespace AuthService\Helper\Sharing\Prep\Client;

final class PrepStatus
{
    public function __construct(
        public readonly string $prepId,
        public readonly string $intent,
        public readonly string $status,
        public readonly ?\DateTimeImmutable $signedAt,
        public readonly ?\DateTimeImmutable $promotedAt,
        public readonly ?\DateTimeImmutable $expiresAt,
        public readonly ?string $permanentResourceId,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            prepId: $raw['prep_id'],
            intent: $raw['intent'],
            status: $raw['status'],
            signedAt:   isset($raw['signed_at'])   ? new \DateTimeImmutable($raw['signed_at'])   : null,
            promotedAt: isset($raw['promoted_at']) ? new \DateTimeImmutable($raw['promoted_at']) : null,
            expiresAt:  isset($raw['expires_at'])  ? new \DateTimeImmutable($raw['expires_at'])  : null,
            permanentResourceId: $raw['permanent_resource_id'] ?? null,
        );
    }
}
