<?php

namespace AuthService\Helper\Sharing\Prep\Client;

final class PrepResult
{
    public function __construct(
        public readonly string $prepId,
        public readonly string $embedUrl,
        public readonly ?\DateTimeImmutable $expiresAt,
        public readonly string $status,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            prepId:    $raw['prep_id'],
            embedUrl:  $raw['embed_url'],
            expiresAt: isset($raw['expires_at']) ? new \DateTimeImmutable($raw['expires_at']) : null,
            status:    $raw['status'] ?? 'prepared',
        );
    }
}
