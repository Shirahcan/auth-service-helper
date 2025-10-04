<?php

namespace AuthService\Helper\Middleware;

use AuthService\Helper\Middleware\Concerns\RespondsWithAuthErrors;
use Closure;
use Illuminate\Http\Request;

/**
 * Middleware to ensure user is authenticated via authservice guard
 *
 * This middleware protects routes by requiring authentication.
 * It does not check for specific roles - use HasRoleMiddleware for that.
 */
class Authenticate
{
    use RespondsWithAuthErrors;

    /**
     * Handle an incoming request - validates that a user is authenticated
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string|null  $guard  Optional guard name (defaults to 'authservice')
     * @return mixed
     */
    public function handle(Request $request, Closure $next, ?string $guard = null)
    {
        // Use authservice guard by default, or the specified guard
        $guard = $guard ?? 'authservice';

        // Check if user is authenticated
        if (!auth($guard)->check()) {
            return $this->unauthorizedResponse('Authentication required');
        }

        // User is authenticated, proceed with request
        return $next($request);
    }
}
