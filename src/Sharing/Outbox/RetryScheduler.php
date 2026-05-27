<?php

namespace AuthService\Helper\Sharing\Outbox;

use Throwable;

final class RetryScheduler
{
    /** Delay (seconds) BEFORE the next retry, indexed by the attempt that just failed. */
    public const SCHEDULE = [
        1 => 60,        // 1 min
        2 => 300,       // 5 min
        3 => 1800,      // 30 min
        4 => 7200,      // 2 h
        5 => 43200,     // 12 h
    ];

    /** Return the delay before the next retry, or null if attempts are exhausted. */
    public static function nextDelayForAttempt(int $attempt): ?int
    {
        return self::SCHEDULE[$attempt] ?? null;
    }

    /**
     * Classify a delivery outcome:
     *   - 5xx        → transient
     *   - 408 / 429  → transient
     *   - other 4xx  → permanent
     *   - 2xx        → not transient (job uses this AFTER detecting non-2xx)
     *   - network/   → transient
     *     exception
     */
    public static function isTransient(?int $responseStatus, ?Throwable $e): bool
    {
        if ($e !== null && $responseStatus === null) {
            return true;
        }
        if ($responseStatus === null) {
            return true;
        }
        if ($responseStatus >= 500 && $responseStatus < 600) {
            return true;
        }
        if (in_array($responseStatus, [408, 429], true)) {
            return true;
        }
        return false;
    }
}
