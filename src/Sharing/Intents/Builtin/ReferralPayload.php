<?php

namespace AuthService\Helper\Sharing\Intents\Builtin;

use AuthService\Helper\Sharing\Intents\Contracts\SharePayload;

final class ReferralPayload implements SharePayload
{
    public function __construct(
        public readonly string $referralCode,
        public readonly ?string $referrerUserId = null,
        public readonly ?string $campaign = null,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            referralCode: $raw['referral_code'],
            referrerUserId: $raw['referrer_user_id'] ?? null,
            campaign: $raw['campaign'] ?? null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'referral_code' => $this->referralCode,
            'referrer_user_id' => $this->referrerUserId,
            'campaign' => $this->campaign,
        ], fn ($v) => $v !== null);
    }

    public static function intentSlug(): string { return 'referral'; }
    public static function intentVersion(): string { return '1.0'; }
}
