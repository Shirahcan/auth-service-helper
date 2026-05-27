<?php

namespace AuthService\Helper\Sharing\Client;

final class ShareResult
{
    public function __construct(
        public readonly string $id,
        public readonly string $userId,
        public readonly string $targetServiceId,
        public readonly string $status,
        public readonly array $grantedRoleIds,
        public readonly array $metadata,
        public readonly ?ConflictRef $conflict = null,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            id: $raw['id'],
            userId: $raw['user_id'],
            targetServiceId: $raw['target_service_id'],
            status: $raw['status'],
            grantedRoleIds: $raw['granted_role_ids'] ?? [],
            metadata: $raw['metadata'] ?? [],
            conflict: isset($raw['conflict']) ? ConflictRef::fromArray($raw['conflict']) : null,
        );
    }
}
