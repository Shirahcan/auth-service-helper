<?php

namespace AuthService\Helper\Sharing\Inbox\Http\Middleware;

use AuthService\Helper\Sharing\Envelope\EnvelopeVerifier;
use AuthService\Helper\Sharing\Envelope\Exceptions\EnvelopeReplayWindowException;
use AuthService\Helper\Sharing\Envelope\Exceptions\EnvelopeSignatureMismatchException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerifyShareEnvelopeSignature
{
    public function handle(Request $request, Closure $next): mixed
    {
        $trustKey = $request->header('X-Trust-Key');
        $signature = $request->header('X-Signature');

        if (!$trustKey) {
            return $this->reject(401, 'missing_trust_key', 'X-Trust-Key header is required');
        }

        $peer = $this->resolvePeerByTrustKey($trustKey);
        if ($peer === null) {
            return $this->reject(401, 'unknown_trust_key', 'Trust key did not match any configured peer');
        }

        if (!$signature) {
            return $this->reject(400, 'missing_signature', 'X-Signature header is required');
        }

        $verifier = new EnvelopeVerifier(
            currentSecret: $peer['config']['current_secret'],
            previousSecret: $peer['config']['previous_secret'] ?? null,
            windowSeconds: (int) config('authservice.sharing.replay_window_seconds', 300),
        );

        try {
            $verifier->verify($request->getContent(), $signature);
        } catch (EnvelopeSignatureMismatchException $e) {
            return $this->reject(400, 'bad_signature', $e->getMessage());
        } catch (EnvelopeReplayWindowException $e) {
            return $this->reject(400, 'replay_window', $e->getMessage());
        }

        $request->attributes->set('source_service_id', $peer['config']['source_service_id']);
        $request->attributes->set('peer_slug', $peer['slug']);

        return $next($request);
    }

    /**
     * @return array{slug:string, config:array<string,mixed>}|null
     */
    private function resolvePeerByTrustKey(string $trustKey): ?array
    {
        $peers = (array) config('authservice.sharing.peers', []);
        foreach ($peers as $slug => $cfg) {
            if (($cfg['trust_key'] ?? null) === $trustKey) {
                return ['slug' => $slug, 'config' => $cfg];
            }
        }
        return null;
    }

    private function reject(int $status, string $reason, string $message): JsonResponse
    {
        return new JsonResponse([
            'error' => $reason,
            'message' => $message,
        ], $status);
    }
}
