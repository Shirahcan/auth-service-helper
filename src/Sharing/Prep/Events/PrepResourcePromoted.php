<?php

namespace AuthService\Helper\Sharing\Prep\Events;

use AuthService\Helper\Sharing\Prep\PrepResource;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fires AFTER /promote creates the permanent record. */
class PrepResourcePromoted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly PrepResource $prep,
        public readonly string $permanentResourceId,
    ) {}
}
