<?php

namespace AuthService\Helper\Sharing\Intents\Builtin;

use AuthService\Helper\Sharing\Intents\Contracts\SharePayload;

final class RevocationNoticePayload implements SharePayload
{
    public function __construct(
        public readonly string $reason,
        public readonly string $effectiveAt,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(reason: $raw['reason'], effectiveAt: $raw['effective_at']);
    }

    public function toArray(): array
    {
        return ['reason' => $this->reason, 'effective_at' => $this->effectiveAt];
    }

    public static function intentSlug(): string { return 'revocation_notice'; }
    public static function intentVersion(): string { return '1.0'; }
}
