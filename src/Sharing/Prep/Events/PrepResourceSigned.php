<?php

namespace AuthService\Helper\Sharing\Prep\Events;

use AuthService\Helper\Sharing\Prep\PrepResource;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fires AFTER /embed/.../submit captures signed_data + flips to signed. */
class PrepResourceSigned
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly PrepResource $prep) {}
}
