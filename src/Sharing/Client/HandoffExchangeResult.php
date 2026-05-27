<?php

namespace AuthService\Helper\Sharing\Client;

final class HandoffExchangeResult
{
    public function __construct(
        public readonly array $user,
        public readonly string $sessionToken,
        public readonly string $shareId,
        public readonly ?string $nextPath = null,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            user: $raw['user'],
            sessionToken: $raw['session_token'],
            shareId: $raw['share_id'],
            nextPath: $raw['next_path'] ?? null,
        );
    }
}
