<?php

namespace AuthService\Helper\Sharing\Exceptions;

class HandoffTokenInvalidException extends \RuntimeException
{
    public const REASON_CONSUMED = 'consumed';
    public const REASON_EXPIRED = 'expired';
    public const REASON_WRONG_TARGET = 'wrong_target';
    public const REASON_UNKNOWN = 'unknown';
    public const REASON_NETWORK = 'network';

    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message ?: "Handoff token invalid: {$reason}");
    }
}
