<?php

namespace AuthService\Helper\Sharing\Envelope;

use AuthService\Helper\Sharing\Envelope\Exceptions\EnvelopeReplayWindowException;
use AuthService\Helper\Sharing\Envelope\Exceptions\EnvelopeSignatureMismatchException;

final class EnvelopeVerifier
{
    public function __construct(
        private readonly string $currentSecret,
        private readonly ?string $previousSecret = null,
        private readonly int $windowSeconds = 300,
    ) {}

    /**
     * Throws on any failure. Returns void on success.
     */
    public function verify(string $canonicalJson, string $signatureHeader, ?int $now = null): void
    {
        $now = $now ?? time();
        $parsed = $this->parseHeader($signatureHeader);

        $age = abs($now - $parsed['t']);
        if ($age > $this->windowSeconds) {
            throw new EnvelopeReplayWindowException(
                "Signature timestamp outside ±{$this->windowSeconds}s window (age={$age}s)"
            );
        }

        $signedPayload = $parsed['t'] . '.' . $canonicalJson;

        $candidates = array_filter([$this->currentSecret, $this->previousSecret]);
        foreach ($candidates as $secret) {
            $expected = hash_hmac('sha256', $signedPayload, $secret);
            if (hash_equals($expected, $parsed['v1'])) {
                return;
            }
        }

        throw new EnvelopeSignatureMismatchException('No signing key matched signature');
    }

    private function parseHeader(string $header): array
    {
        if (!preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $header, $m)) {
            throw new EnvelopeSignatureMismatchException("Malformed signature header: {$header}");
        }
        return ['t' => (int) $m[1], 'v1' => $m[2]];
    }
}
