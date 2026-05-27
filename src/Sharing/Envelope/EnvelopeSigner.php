<?php

namespace AuthService\Helper\Sharing\Envelope;

final class EnvelopeSigner
{
    public function __construct(private readonly string $secret) {}

    /**
     * Returns the X-Signature header value: t=<unix>,v1=<hmac_sha256_hex>
     * HMAC input is "{t}.{canonical_json}".
     */
    public function sign(ShareEnvelope $envelope, ?int $at = null): string
    {
        $t = $at ?? time();
        $signedPayload = $t . '.' . $envelope->toCanonicalJson();
        $sig = hash_hmac('sha256', $signedPayload, $this->secret);
        return "t={$t},v1={$sig}";
    }
}
