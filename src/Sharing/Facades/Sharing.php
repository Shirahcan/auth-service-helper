<?php

namespace AuthService\Helper\Sharing\Facades;

use AuthService\Helper\Sharing\SharingService;
use Illuminate\Support\Facades\Facade;

/**
 * Static facade for the SharingService. Resolved via the Laravel
 * container; bound as a singleton in SharingServiceProvider::register().
 *
 * @method static \AuthService\Helper\Sharing\Client\ShareResult shareUser(string $userId, string $targetService, ?string $intent = null, array $grantedRoles = [], array $metadata = [], bool $strictOnConflict = false)
 * @method static \AuthService\Helper\Sharing\Client\HandoffMintResult mintHandoffToken(string $shareId, ?string $nextPath = null)
 * @method static void revokeShare(string $shareId, ?string $reason = null)
 * @method static array resolveCollision(string $conflictId, string $strategy, array $params = [])
 * @method static array waitForMergeCompletion(string $conflictId, int $timeoutSeconds = 30)
 */
class Sharing extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SharingService::class;
    }
}
