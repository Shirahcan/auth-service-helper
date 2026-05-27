<?php

namespace AuthService\Helper\Sharing\Inbox\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lightweight shared-secret check so only same-host Next backend code
 * can call /api/v1/inbound/handoff/exchange. Mirrors the pattern the
 * spec §8 calls out: "consumeHandoffToken POSTs to destination's PHP
 * backend /api/v1/inbound/handoff/exchange (helper-provided controller)".
 */
class VerifyInternalToken
{
    public function handle(Request $request, Closure $next): mixed
    {
        $expected = (string) config('authservice-sharing.internal_token', '');
        $provided = (string) $request->header('X-Internal-Token', '');

        if ($expected === '' || !hash_equals($expected, $provided)) {
            return new JsonResponse([
                'error' => 'unauthorized',
                'message' => 'Valid X-Internal-Token header required',
            ], 401);
        }

        return $next($request);
    }
}
