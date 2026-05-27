<?php

namespace AuthService\Helper\Sharing\Exceptions;

use AuthService\Helper\Sharing\Client\ConflictRef;

class UserShareCollisionException extends \RuntimeException
{
    public function __construct(
        public readonly string $conflictId,
        public readonly string $sourceUserId,
        public readonly string $targetExistingUserId,
        public readonly string $resolutionUrl,
        string $message = 'User share collision detected',
    ) {
        parent::__construct($message);
    }

    public static function fromConflict(ConflictRef $c, string $resolutionUrl): self
    {
        return new self(
            conflictId: $c->id,
            sourceUserId: $c->sourceUserId,
            targetExistingUserId: $c->targetExistingUserId,
            resolutionUrl: $resolutionUrl,
        );
    }
}
