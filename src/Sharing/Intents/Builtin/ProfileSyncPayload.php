<?php

namespace AuthService\Helper\Sharing\Intents\Builtin;

use AuthService\Helper\Sharing\Intents\Contracts\SharePayload;

final class ProfileSyncPayload implements SharePayload
{
    public function __construct(
        public readonly array $fieldsChanged,
        public readonly array $snapshot,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            fieldsChanged: $raw['fields_changed'] ?? [],
            snapshot: $raw['snapshot'] ?? [],
        );
    }

    public function toArray(): array
    {
        return ['fields_changed' => $this->fieldsChanged, 'snapshot' => $this->snapshot];
    }

    public static function intentSlug(): string { return 'profile_sync'; }
    public static function intentVersion(): string { return '1.0'; }
}
