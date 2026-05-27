<?php

namespace AuthService\Helper\Sharing\Prep;

final class PromoteResult
{
    public function __construct(
        public readonly string $permanentResourceId,
        public readonly string $state,
    ) {}

    public function toArray(): array
    {
        return [
            'permanent_resource_id' => $this->permanentResourceId,
            'state' => $this->state,
        ];
    }
}
