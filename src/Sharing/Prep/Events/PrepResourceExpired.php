<?php

namespace AuthService\Helper\Sharing\Prep\Events;

use AuthService\Helper\Sharing\Prep\PrepResource;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fires from sharing:gc-prep BEFORE the row is deleted. */
class PrepResourceExpired
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly PrepResource $prep) {}
}
