<?php

namespace AuthService\Helper\Sharing\Prep\Http\Controllers;

use AuthService\Helper\Sharing\Prep\Events\PrepResourceSigned;
use AuthService\Helper\Sharing\Prep\Intents\PrepIntentRegistry;
use AuthService\Helper\Sharing\Prep\PrepResource;
use AuthService\Helper\Sharing\Prep\PrepResourceRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpFoundation\Response;

class EmbedController
{
    public function __construct(
        protected PrepResourceRepository $repo,
        protected PrepIntentRegistry $intents,
    ) {}

    public function render(Request $request, string $intentSlug, string $prepId): Response
    {
        $row = $this->repo->find($prepId);
        if (!$row || $row->intent !== $intentSlug || $row->hasExpired()) {
            return new Response('Not found', 404);
        }
        if (!$this->intents->has($intentSlug)) {
            return new Response('Intent handler missing', 500);
        }
        if (in_array($row->status, [PrepResource::STATUS_PROMOTED, PrepResource::STATUS_EXPIRED], true)) {
            return new Response('Not available', 410);
        }

        return $this->intents->get($intentSlug)->render($row);
    }

    public function submit(Request $request, string $intentSlug, string $prepId): JsonResponse
    {
        $row = $this->repo->find($prepId);
        if (!$row || $row->intent !== $intentSlug || $row->hasExpired()) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }
        if (!$this->intents->has($intentSlug)) {
            return new JsonResponse(['error' => 'no_handler'], 500);
        }
        if ($row->status !== PrepResource::STATUS_PREPARED) {
            return new JsonResponse([
                'error'  => 'invalid_state',
                'status' => $row->status,
            ], 409);
        }

        try {
            $signedData = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($signedData)) {
                throw new \JsonException('expected JSON object');
            }
        } catch (\JsonException $e) {
            return new JsonResponse(['error' => 'invalid_body', 'message' => $e->getMessage()], 400);
        }

        $handler = $this->intents->get($intentSlug);
        try {
            $handler->submit($row, $signedData);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'handler_rejected', 'message' => $e->getMessage()], 422);
        }

        $this->repo->markSigned(
            $row,
            signedData: $signedData,
            ttlSignedHours: (int) config('authservice.sharing.prep.ttl_signed_hours', 72),
        );

        Event::dispatch(new PrepResourceSigned($row->fresh()));

        return new JsonResponse([
            'status'    => 'signed',
            'signed_at' => $row->fresh()->signed_at?->toIso8601String(),
        ], 200);
    }
}
