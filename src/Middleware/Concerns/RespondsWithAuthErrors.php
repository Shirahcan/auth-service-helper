<?php

namespace AuthService\Helper\Middleware\Concerns;

use Illuminate\Http\Response;

/**
 * Trait for handling authentication error responses
 *
 * Provides consistent response handling for authentication failures
 * across different middleware classes. Supports both web and API responses.
 */
trait RespondsWithAuthErrors
{
    /**
     * Return unauthorized response for unauthenticated requests
     *
     * Returns JSON response for API requests (401 status)
     * Returns redirect to login for web requests
     *
     * @param string $message The error message to display
     * @return Response
     */
    protected function unauthorizedResponse(string $message): Response
    {
        if (request()->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'errors' => ['auth' => 'User is not authenticated']
            ], Response::HTTP_UNAUTHORIZED);
        }

        return response()->redirectToRoute('auth.login')
            ->with('error', $message);
    }
}
