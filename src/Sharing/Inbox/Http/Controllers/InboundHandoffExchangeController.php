<?php

namespace AuthService\Helper\Sharing\Inbox\Http\Controllers;

use AuthService\Helper\Sharing\Client\HandoffExchangeResult;
use AuthService\Helper\Sharing\Client\HandoffTokenClient;
use AuthService\Helper\Sharing\Inbox\Events\InboundHandoffCompleted;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

class InboundHandoffExchangeController
{
    public function __construct(
        protected HandoffTokenClient $handoffTokens,
    ) {}

    public function exchange(Request $request): JsonResponse
    {
        $token = (string) $request->input('token', '');
        if ($token === '') {
            return new JsonResponse([
                'error' => 'missing_token',
                'message' => 'POST body must include a non-empty "token" field',
            ], 400);
        }

        try {
            $result = $this->handoffTokens->exchange($token);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => 'exchange_failed',
                'message' => $e->getMessage(),
            ], 400);
        }

        if ($result instanceof HandoffExchangeResult) {
            $resultArray = [
                'user' => $result->user,
                'session_token' => $result->sessionToken,
                'share_id' => $result->shareId,
                'next_path' => $result->nextPath,
            ];
        } else {
            $resultArray = is_array($result) ? $result : (array) $result;
        }

        Event::dispatch(new InboundHandoffCompleted(
            shareId: (string) ($resultArray['share_id'] ?? ''),
            userId: (string) ($resultArray['user']['uuid'] ?? ''),
            targetServiceId: (string) config('authservice.service_id', ''),
            nextPath: (string) ($resultArray['next_path'] ?? '/'),
            consumedAt: now()->toIso8601String(),
        ));

        return new JsonResponse([
            'user' => $resultArray['user'] ?? null,
            'session_token' => $resultArray['session_token'] ?? null,
            'share_id' => $resultArray['share_id'] ?? null,
            'next_path' => $resultArray['next_path'] ?? '/',
        ], 200);
    }
}
