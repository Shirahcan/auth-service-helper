<?php

namespace AuthService\Helper\Sharing\Prep\Client;

final class PromoteResultDto
{
    public function __construct(
        public readonly string $permanentResourceId,
        public readonly string $state,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            permanentResourceId: $raw['permanent_resource_id'],
            state: $raw['state'] ?? 'active',
        );
    }
}
