<?php

namespace AuthService\Helper\Sharing\Prep\Intents;

use AuthService\Helper\Sharing\Prep\PrepResource;
use AuthService\Helper\Sharing\Prep\PromoteResult;
use Symfony\Component\HttpFoundation\Response;

interface PrepIntentHandler
{
    public static function intentSlug(): string;
    public static function intentVersion(): string;

    /** HTML rendered by GET /sharing/embed/{slug}/{prep_id} for the iframe. */
    public function render(PrepResource $temp): Response;

    /** Validate + persist signature data. Called from POST /embed/{slug}/{id}/submit. Throw InvalidSignatureException to reject. */
    public function submit(PrepResource $temp, array $signedData): void;

    /** Create the permanent resource. $share is the auth-service user_share row (verified by /promote before calling). */
    public function promote(PrepResource $temp, array $share): PromoteResult;
}
