<?php

namespace AuthService\Helper\Sharing\Prep\Events;

use AuthService\Helper\Sharing\Prep\PrepResource;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fires AFTER /prepare creates a NEW prep row (not on idempotent return of existing). */
class PrepResourceCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly PrepResource $prep) {}
}
