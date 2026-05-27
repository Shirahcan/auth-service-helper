<?php

namespace AuthService\Helper\Sharing\Intents\Builtin;

use AuthService\Helper\Sharing\Intents\Contracts\SharePayload;

final class InvitePayload implements SharePayload
{
    public function __construct(
        public readonly string $invitationMessage,
        public readonly array $grantedRoles,
        public readonly ?string $expiresAt = null,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            invitationMessage: $raw['invitation_message'],
            grantedRoles: $raw['granted_roles'] ?? [],
            expiresAt: $raw['expires_at'] ?? null,
        );
    }

    public function toArray(): array
    {
        $arr = [
            'invitation_message' => $this->invitationMessage,
            'granted_roles' => $this->grantedRoles,
        ];
        if ($this->expiresAt) $arr['expires_at'] = $this->expiresAt;
        return $arr;
    }

    public static function intentSlug(): string { return 'invite'; }
    public static function intentVersion(): string { return '1.0'; }
}
