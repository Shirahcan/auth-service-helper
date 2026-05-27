<?php

namespace AuthService\Helper\Sharing\Intents\Builtin;

use AuthService\Helper\Sharing\Intents\Contracts\SharePayload;

final class StatusUpdatePayload implements SharePayload
{
    public function __construct(
        public readonly string $status,
        public readonly string $previousStatus,
        public readonly string $effectiveAt,
        public readonly ?string $notes = null,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            status: $raw['status'],
            previousStatus: $raw['previous_status'],
            effectiveAt: $raw['effective_at'],
            notes: $raw['notes'] ?? null,
        );
    }

    public function toArray(): array
    {
        $arr = [
            'status' => $this->status,
            'previous_status' => $this->previousStatus,
            'effective_at' => $this->effectiveAt,
        ];
        if ($this->notes !== null) $arr['notes'] = $this->notes;
        return $arr;
    }

    public static function intentSlug(): string { return 'status_update'; }
    public static function intentVersion(): string { return '1.0'; }
}
