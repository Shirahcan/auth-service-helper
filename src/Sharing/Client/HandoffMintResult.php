<?php

namespace AuthService\Helper\Sharing\Client;

final class HandoffMintResult
{
    public function __construct(
        public readonly string $token,
        public readonly string $redirectUrl,
        public readonly string $expiresAt,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            token: $raw['token'],
            redirectUrl: $raw['redirect_url'],
            expiresAt: $raw['expires_at'],
        );
    }
}
