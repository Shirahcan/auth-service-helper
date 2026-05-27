<?php

namespace AuthService\Helper\Sharing\Prep\Http\Controllers;

use AuthService\Helper\Sharing\Envelope\Exceptions\InvalidEnvelopeException;
use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use AuthService\Helper\Sharing\Prep\Events\PrepResourceCreated;
use AuthService\Helper\Sharing\Prep\Intents\PrepIntentRegistry;
use AuthService\Helper\Sharing\Prep\PrepResourceRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

class PrepareController
{
    public function __construct(
        protected PrepResourceRepository $repo,
        protected PrepIntentRegistry $intents,
    ) {}

    public function prepare(Request $request): JsonResponse
    {
        // 1. Parse envelope (middleware already verified HMAC + replay window).
        try {
            $raw = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($raw)) {
                throw new InvalidEnvelopeException('body must be a JSON object');
            }
            $env = ShareEnvelope::fromArray($raw);
        } catch (\JsonException | InvalidEnvelopeException $e) {
            return $this->error(400, 'invalid_envelope', $e->getMessage());
        }

        $payload = $env->payload;
        if (($payload['operation'] ?? null) !== 'prepare') {
            return $this->error(400, 'invalid_operation', 'expected operation=prepare');
        }

        $intentSlug = $payload['intent_slug'] ?? null;
        if (!$intentSlug || !is_string($intentSlug)) {
            return $this->error(400, 'missing_intent_slug', 'payload.intent_slug is required');
        }
        if (!$this->intents->has($intentSlug)) {
            return $this->error(400, 'unknown_intent_slug', "no handler registered for intent '{$intentSlug}'");
        }

        $sourceServiceId = (string) ($request->attributes->get('source_service_id') ?? $env->sourceServiceId);

        $existing = $this->repo->findByIdempotency($sourceServiceId, $env->idempotencyKey);

        $row = $this->repo->upsert(
            sourceServiceId: $sourceServiceId,
            idempotencyKey:  $env->idempotencyKey,
            intent:          $intentSlug,
            intentVersion:   (string) ($payload['intent_version'] ?? '1.0'),
            sourceResource:  (array) ($payload['source_resource'] ?? []),
            studentData:     (array) ($payload['student_data'] ?? []),
            payload:         (array) ($payload['payload'] ?? []),
            returnTo:        $payload['return_to'] ?? null,
            ttlPreparedHours: (int) config('authservice.sharing.prep.ttl_prepared_hours', 24),
        );

        if (!$existing) {
            Event::dispatch(new PrepResourceCreated($row));
        }

        return new JsonResponse([
            'prep_id'    => $row->id,
            'embed_url'  => $this->buildEmbedUrl($intentSlug, $row->id),
            'expires_at' => $row->expires_at?->toIso8601String(),
            'status'     => $row->status,
        ], 200);
    }

    protected function buildEmbedUrl(string $intentSlug, string $prepId): string
    {
        $base = rtrim((string) config(
            'authservice.sharing.prep.embed_base_url',
            (string) config('app.url'),
        ), '/');
        return "{$base}/sharing/embed/{$intentSlug}/{$prepId}";
    }

    protected function error(int $status, string $reason, string $message): JsonResponse
    {
        return new JsonResponse(['error' => $reason, 'message' => $message], $status);
    }
}
