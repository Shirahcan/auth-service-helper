<?php

namespace AuthService\Helper\Sharing\Client;

final class ConflictRef
{
    public function __construct(
        public readonly string $id,
        public readonly string $sourceUserId,
        public readonly string $targetExistingUserId,
        public readonly string $status,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            id: $raw['id'],
            sourceUserId: $raw['source_user_id'],
            targetExistingUserId: $raw['target_existing_user_id'],
            status: $raw['status'],
        );
    }
}
