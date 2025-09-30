<?php

namespace AuthService\Helper\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class HasRoleMiddleware
{
    /**
     * Handle an incoming request - validates that the authenticated user has the required role(s)
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  ...$roles  Required roles (OR logic - user needs at least one)
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        // Get authenticated user from session
        $user = session('auth_user');

        if (!$user) {
            return $this->unauthorizedResponse('Authentication required');
        }

        // Extract user roles from session data
        $userRoles = $user['user']['roles'] ?? [];

        if (empty($roles)) {
            // No specific roles required, just authentication
            return $next($request);
        }

        // Check if user has any of the required roles
        $hasRole = !empty(array_intersect($roles, $userRoles));

        if (!$hasRole) {
            return $this->forbiddenResponse($roles);
        }

        return $next($request);
    }

    /**
     * Return unauthorized response
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

    /**
     * Return forbidden response
     */
    protected function forbiddenResponse(array $requiredRoles): Response
    {
        $rolesText = implode(', ', $requiredRoles);
        $message = "Access denied. Required role(s): {$rolesText}";

        if (request()->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'errors' => [
                    'role' => 'User does not have required role(s)',
                    'required_roles' => $requiredRoles
                ]
            ], Response::HTTP_FORBIDDEN);
        }

        return response()->redirectToRoute('auth.login')
            ->with('error', $message);
    }
}
