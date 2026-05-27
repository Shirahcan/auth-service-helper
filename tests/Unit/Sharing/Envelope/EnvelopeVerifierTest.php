<?php

namespace Tests\Unit\Sharing\Envelope;

use AuthService\Helper\Sharing\Envelope\EnvelopeSigner;
use AuthService\Helper\Sharing\Envelope\EnvelopeVerifier;
use AuthService\Helper\Sharing\Envelope\Exceptions\EnvelopeReplayWindowException;
use AuthService\Helper\Sharing\Envelope\Exceptions\EnvelopeSignatureMismatchException;
use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use PHPUnit\Framework\TestCase;

class EnvelopeVerifierTest extends TestCase
{
    public function test_verifies_signature_signed_with_current_secret(): void
    {
        $env = $this->envelope();
        $header = (new EnvelopeSigner('current'))->sign($env, at: 1700000000);

        $verifier = new EnvelopeVerifier('current', null, 300);
        $verifier->verify($env->toCanonicalJson(), $header, now: 1700000010);
        $this->assertTrue(true); // no exception = pass
    }

    public function test_verifies_signature_signed_with_previous_secret_during_rotation(): void
    {
        $env = $this->envelope();
        $header = (new EnvelopeSigner('old'))->sign($env, at: 1700000000);

        $verifier = new EnvelopeVerifier('new', 'old', 300);
        $verifier->verify($env->toCanonicalJson(), $header, now: 1700000010);
        $this->assertTrue(true);
    }

    public function test_rejects_outside_replay_window(): void
    {
        $env = $this->envelope();
        $header = (new EnvelopeSigner('s'))->sign($env, at: 1700000000);

        $verifier = new EnvelopeVerifier('s', null, 300);
        $this->expectException(EnvelopeReplayWindowException::class);
        $verifier->verify($env->toCanonicalJson(), $header, now: 1700000301);
    }

    public function test_rejects_bad_signature(): void
    {
        $env = $this->envelope();
        $header = (new EnvelopeSigner('wrong'))->sign($env, at: 1700000000);

        $verifier = new EnvelopeVerifier('right', null, 300);
        $this->expectException(EnvelopeSignatureMismatchException::class);
        $verifier->verify($env->toCanonicalJson(), $header, now: 1700000010);
    }

    public function test_rejects_malformed_header(): void
    {
        $verifier = new EnvelopeVerifier('s', null, 300);
        $this->expectException(EnvelopeSignatureMismatchException::class);
        $verifier->verify('{}', 'garbage', now: 1700000000);
    }

    private function envelope(): ShareEnvelope
    {
        return ShareEnvelope::fromArray([
            'envelope_version' => '1', 'message_id' => 'm', 'correlation_id' => 'c',
            'intent' => 'i', 'intent_version' => '1.0',
            'source_service_id' => '00000000-0000-0000-0000-000000000001',
            'target_service_id' => '00000000-0000-0000-0000-000000000002',
            'user_id' => '00000000-0000-0000-0000-000000000003',
            'idempotency_key' => 'k', 'issued_at' => '2026-05-27T10:00:00Z',
            'payload' => [],
        ]);
    }
}
