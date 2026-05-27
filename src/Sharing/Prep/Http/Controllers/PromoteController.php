<?php

namespace AuthService\Helper\Sharing\Prep\Http\Controllers;

use AuthService\Helper\Sharing\Client\UserShareClient;
use AuthService\Helper\Sharing\Envelope\Exceptions\InvalidEnvelopeException;
use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use AuthService\Helper\Sharing\Prep\Events\PrepResourcePromoted;
use AuthService\Helper\Sharing\Prep\Intents\PrepIntentRegistry;
use AuthService\Helper\Sharing\Prep\PrepResource;
use AuthService\Helper\Sharing\Prep\PrepResourceRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

class PromoteController
{
    public function __construct(
        protected PrepResourceRepository $repo,
        protected PrepIntentRegistry $intents,
        protected UserShareClient $shares,
    ) {}

    public function promote(Request $request, string $prepId): JsonResponse
    {
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
        if (($payload['operation'] ?? null) !== 'promote') {
            return $this->error(400, 'invalid_operation', 'expected operation=promote');
        }

        $shareId = $payload['share_id'] ?? null;
        if (!$shareId || !is_string($shareId)) {
            return $this->error(400, 'missing_share_id', 'payload.share_id is required (created via Sharing::shareUser post-payment)');
        }

        $row = $this->repo->find($prepId);
        if (!$row) {
            return $this->error(404, 'prep_not_found', "no prep entry for {$prepId}");
        }

        if ($row->isPromoted()) {
            return new JsonResponse([
                'error' => 'already_promoted',
                'permanent_resource_id' => $row->permanent_resource_id,
                'state' => 'active',
            ], 409);
        }

        if ($row->hasExpired()) {
            return $this->error(404, 'prep_expired', "prep {$prepId} expired at {$row->expires_at?->toIso8601String()}");
        }

        if ($row->status !== PrepResource::STATUS_SIGNED) {
            return new JsonResponse([
                'error'  => 'not_signed',
                'status' => $row->status,
                'message' => "prep must be in 'signed' state before promote (currently '{$row->status}')",
            ], 409);
        }

        $share = null;
        try {
            $shareResult = $this->shares->get($shareId);
            // Tests may inject a Mockery mock returning either ShareResult or array
            if ($shareResult instanceof \AuthService\Helper\Sharing\Client\ShareResult) {
                $share = [
                    'id' => $shareResult->id,
                    'user_id' => $shareResult->userId,
                    'target_service_id' => $shareResult->targetServiceId,
                    'status' => $shareResult->status,
                ];
            } elseif (is_array($shareResult)) {
                $share = $shareResult;
            } else {
                $share = null;
            }
        } catch (\Throwable $e) {
            return $this->error(502, 'share_lookup_failed', $e->getMessage());
        }
        if (!$share || ($share['status'] ?? null) !== 'active') {
            return $this->error(403, 'share_invalid', "share {$shareId} not found or not active");
        }

        if (!$this->intents->has($row->intent)) {
            return $this->error(500, 'no_handler', "intent '{$row->intent}' has no registered handler");
        }

        try {
            $result = $this->intents->get($row->intent)->promote($row, $share);
        } catch (\Throwable $e) {
            return $this->error(500, 'promotion_failed', $e->getMessage());
        }

        $this->repo->markPromoted($row, permanentResourceId: $result->permanentResourceId);
        Event::dispatch(new PrepResourcePromoted($row->fresh(), $result->permanentResourceId));

        return new JsonResponse([
            'permanent_resource_id' => $result->permanentResourceId,
            'state'                 => $result->state,
        ], 200);
    }

    public function status(Request $request, string $prepId): JsonResponse
    {
        $row = $this->repo->find($prepId);
        if (!$row) {
            return $this->error(404, 'prep_not_found', "no prep entry for {$prepId}");
        }
        return new JsonResponse([
            'prep_id' => $row->id,
            'intent'  => $row->intent,
            'status'  => $row->status,
            'signed_at'   => $row->signed_at?->toIso8601String(),
            'promoted_at' => $row->promoted_at?->toIso8601String(),
            'expires_at'  => $row->expires_at?->toIso8601String(),
            'permanent_resource_id' => $row->permanent_resource_id,
        ], 200);
    }

    protected function error(int $status, string $reason, string $message): JsonResponse
    {
        return new JsonResponse(['error' => $reason, 'message' => $message], $status);
    }
}
