<?php

namespace AuthService\Helper\Middleware;

use AuthService\Helper\Services\AuthServiceClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Exception\RequestException;

/**
 * Trusted Service Middleware
 *
 * This middleware validates service-to-service trust relationships using trust keys.
 * It extracts the trust key from the request, validates it against the auth service,
 * checks required permissions if specified, and injects service trust data into the request.
 *
 * Features:
 * - Trust key extraction from multiple headers (X-Trust-Key, X-API-Key, Authorization Bearer)
 * - Validation caching with SHA-256 hash for performance
 * - Permission-based access control
 * - Comprehensive error handling and logging
 * - Request data injection for downstream use
 *
 * Usage:
 * Route::middleware(['trusted.service'])->group(function () {
 *     // Routes accessible by any trusted service
 * });
 *
 * Route::middleware(['trusted.service:read,write'])->group(function () {
 *     // Routes requiring specific permissions
 * });
 *
 * @package AuthService\Helper\Middleware
 */
class TrustedServiceMiddleware
{
    /**
     * The AuthServiceClient instance for validating trust keys
     *
     * @var AuthServiceClient
     */
    protected AuthServiceClient $authServiceClient;

    /**
     * Create a new middleware instance
     *
     * @param AuthServiceClient $authServiceClient
     */
    public function __construct(AuthServiceClient $authServiceClient)
    {
        $this->authServiceClient = $authServiceClient;
    }

    /**
     * Handle an incoming request
     *
     * Validates the trust key from the request and checks required permissions.
     * Injects service trust data into the request for use in controllers.
     *
     * @param Request $request The incoming HTTP request
     * @param Closure $next The next middleware in the stack
     * @param string ...$requiredPermissions Optional list of required permissions (e.g., 'read', 'write')
     * @return mixed
     *
     * @throws \Exception When unexpected errors occur during validation
     */
    public function handle(Request $request, Closure $next, string ...$requiredPermissions)
    {
        // Extract trust key from request headers
        $trustKey = $this->extractTrustKey($request);

        if (!$trustKey) {
            Log::warning('Trust key missing in request', [
                'url' => $request->url(),
                'method' => $request->method(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Trust key is required',
                'errors' => ['trust_key' => 'Missing trust key in request headers (X-Trust-Key, X-API-Key, or Authorization Bearer)']
            ], Response::HTTP_UNAUTHORIZED);
        }

        try {
            // Validate the trust key with caching
            $validationData = $this->validateTrustKeyWithCache($trustKey);

            // Check if validation was successful
            if (!($validationData['valid'] ?? false)) {
                Log::warning('Invalid trust key used', [
                    'trust_key_hash' => hash('sha256', $trustKey),
                    'url' => $request->url(),
                    'reason' => $validationData['message'] ?? 'Unknown reason',
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $validationData['message'] ?? 'Invalid trust key',
                    'errors' => ['trust_key' => $validationData['message'] ?? 'The provided trust key is invalid or has expired']
                ], Response::HTTP_FORBIDDEN);
            }

            // Check if trust key has required permissions
            if (!empty($requiredPermissions)) {
                $hasPermission = $this->checkPermissions(
                    $validationData['permissions'] ?? [],
                    $requiredPermissions
                );

                if (!$hasPermission) {
                    Log::warning('Insufficient permissions for trust key', [
                        'trust_key_hash' => hash('sha256', $trustKey),
                        'trust_key_id' => $validationData['trust_key_id'] ?? null,
                        'required_permissions' => $requiredPermissions,
                        'granted_permissions' => $validationData['permissions'] ?? [],
                        'calling_service' => $validationData['calling_service'] ?? null,
                        'target_service' => $validationData['target_service'] ?? null,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Insufficient permissions',
                        'errors' => [
                            'permissions' => sprintf(
                                'This trust key does not have the required permissions: %s',
                                implode(', ', $requiredPermissions)
                            )
                        ]
                    ], Response::HTTP_FORBIDDEN);
                }
            }

            // Inject service trust data into the request
            $this->injectServiceData($request, $validationData);

            Log::info('Trust key validated successfully', [
                'trust_key_id' => $validationData['trust_key_id'] ?? null,
                'calling_service' => $validationData['calling_service'] ?? null,
                'target_service' => $validationData['target_service'] ?? null,
                'permissions' => $validationData['permissions'] ?? [],
                'url' => $request->url(),
            ]);

            return $next($request);

        } catch (RequestException $e) {
            $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : null;
            $responseBody = null;

            if ($e->getResponse()) {
                try {
                    $responseBody = json_decode($e->getResponse()->getBody()->getContents(), true);
                } catch (\Exception $jsonException) {
                    // Ignore JSON parsing errors
                }
            }

            Log::warning('Trust key validation request failed', [
                'trust_key_hash' => hash('sha256', $trustKey),
                'error' => $e->getMessage(),
                'status_code' => $statusCode,
                'response_body' => $responseBody,
                'url' => $request->url(),
            ]);

            // If auth service returned 401 or 403, treat as invalid key
            if (in_array($statusCode, [401, 403])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid trust key',
                    'errors' => ['trust_key' => $responseBody['message'] ?? 'The provided trust key is invalid or has expired']
                ], Response::HTTP_FORBIDDEN);
            }

            // Otherwise, it's a service unavailable error
            return response()->json([
                'success' => false,
                'message' => 'Trust validation service unavailable',
                'errors' => ['service' => 'Unable to validate trust key at this time. Please try again later.']
            ], Response::HTTP_SERVICE_UNAVAILABLE);

        } catch (\Exception $e) {
            Log::error('Trust key validation error', [
                'trust_key_hash' => hash('sha256', $trustKey),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'url' => $request->url(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Trust validation failed',
                'errors' => ['trust' => 'An internal error occurred during trust validation']
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Extract trust key from request with priority order
     *
     * Priority order:
     * 1. X-Trust-Key header (dedicated trust key header)
     * 2. X-API-Key header (standard API key header)
     * 3. Authorization Bearer token
     *
     * @param Request $request The incoming HTTP request
     * @return string|null The extracted trust key or null if not found
     */
    protected function extractTrustKey(Request $request): ?string
    {
        // Priority 1: X-Trust-Key header (dedicated trust key header)
        if ($trustKey = $request->header('X-Trust-Key')) {
            return trim($trustKey);
        }

        // Priority 2: X-API-Key header (standard API key header)
        if ($trustKey = $request->header('X-API-Key')) {
            return trim($trustKey);
        }

        // Priority 3: Authorization Bearer token
        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            return trim(substr($authHeader, 7));
        }

        return null;
    }

    /**
     * Validate trust key with caching
     *
     * Validates the trust key against the auth service. If caching is enabled,
     * the validation result is cached using a SHA-256 hash of the trust key
     * to improve performance and reduce load on the auth service.
     *
     * Cache configuration:
     * - authservice.cache_trust_results: Enable/disable caching (default: true)
     * - authservice.trust_cache_ttl: Cache TTL in seconds (default: 900 = 15 minutes)
     *
     * @param string $trustKey The trust key to validate
     * @return array The validation result containing:
     *               - valid: bool Whether the key is valid
     *               - calling_service: string The calling service identifier
     *               - target_service: string The target service identifier
     *               - permissions: array The granted permissions
     *               - trust_key_id: int The trust key ID
     *               - message: string Any error or status message
     *
     * @throws RequestException When the validation request fails
     * @throws \Exception When unexpected errors occur
     */
    protected function validateTrustKeyWithCache(string $trustKey): array
    {
        $cacheEnabled = config('authservice.cache_trust_results', true);
        $cacheTtl = config('authservice.trust_cache_ttl', 900); // 15 minutes

        if (!$cacheEnabled) {
            return $this->authServiceClient->validateTrustKey($trustKey);
        }

        $cacheKey = 'trust_key_validation:' . hash('sha256', $trustKey);

        return Cache::remember($cacheKey, $cacheTtl, function () use ($trustKey) {
            return $this->authServiceClient->validateTrustKey($trustKey);
        });
    }

    /**
     * Check if granted permissions satisfy required permissions
     *
     * Validates that the trust key has all required permissions.
     * Permission matching is case-insensitive.
     *
     * @param array $grantedPermissions The permissions granted to the trust key
     * @param array $requiredPermissions The permissions required for the route
     * @return bool True if all required permissions are granted, false otherwise
     */
    protected function checkPermissions(array $grantedPermissions, array $requiredPermissions): bool
    {
        if (empty($requiredPermissions)) {
            return true;
        }

        // Normalize all permissions to lowercase for case-insensitive comparison
        $grantedPermissions = array_map('strtolower', $grantedPermissions);
        $requiredPermissions = array_map('strtolower', $requiredPermissions);

        // Check if all required permissions are in granted permissions
        foreach ($requiredPermissions as $required) {
            if (!in_array($required, $grantedPermissions)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Inject service trust data into the request
     *
     * Merges trust validation data into the request object so it can be
     * accessed in controllers and other middleware. The data is stored
     * under the 'service_trust' key.
     *
     * Example usage in controller:
     * <code>
     * $callingService = $request->input('service_trust.calling_service');
     * $permissions = $request->input('service_trust.permissions');
     * </code>
     *
     * @param Request $request The incoming HTTP request
     * @param array $validationData The validation data from the auth service
     * @return void
     */
    protected function injectServiceData(Request $request, array $validationData): void
    {
        $request->merge([
            'service_trust' => [
                'calling_service' => $validationData['calling_service'] ?? null,
                'target_service' => $validationData['target_service'] ?? null,
                'permissions' => $validationData['permissions'] ?? [],
                'trust_key_id' => $validationData['trust_key_id'] ?? null,
                'validated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Invalidate cached validation for a trust key
     *
     * This static method allows manually invalidating the cache for a specific
     * trust key. Useful when a trust key is revoked, modified, or expired.
     *
     * Example usage:
     * <code>
     * TrustedServiceMiddleware::invalidateCache($trustKey);
     * </code>
     *
     * @param string $trustKey The trust key whose cache should be invalidated
     * @return void
     */
    public static function invalidateCache(string $trustKey): void
    {
        $cacheKey = 'trust_key_validation:' . hash('sha256', $trustKey);
        Cache::forget($cacheKey);

        Log::info('Trust key cache invalidated', [
            'trust_key_hash' => hash('sha256', $trustKey),
        ]);
    }
}
