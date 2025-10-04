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
     * Supports both standard roles and service-scoped roles:
     * - Standard: 'admin', 'manager'
     * - Service-scoped: 'documents-service:editor'
     * - Global admin roles always have access
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  ...$roles  Required roles (OR logic - user needs at least one)
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        // Get authenticated user via the authservice guard
        $user = auth('authservice')->user();

        if (!$user) {
            return $this->unauthorizedResponse('Authentication required');
        }

        if (empty($roles)) {
            // No specific roles required, just authentication
            return $next($request);
        }

        // Get current service slug for service-scoped role checking
        $currentService = config('authservice.service_slug');

        // Check if user has any of the required roles
        foreach ($roles as $role) {
            if ($currentService) {
                // Use service-scoped role checking (includes exact match, scoped roles, and global admin)
                if ($user->hasServiceRole($currentService, $role)) {
                    return $next($request);
                }
            } else {
                // Fallback to standard role checking when service slug is not configured
                if ($user->hasRole($role)) {
                    return $next($request);
                }
            }
        }

        // User doesn't have any of the required roles
        return $this->forbiddenResponse($roles);
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
