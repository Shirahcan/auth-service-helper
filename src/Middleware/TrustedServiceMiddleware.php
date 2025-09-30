<?php

namespace AuthService\Helper\Middleware;

use AuthService\Helper\Services\AuthServiceClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Exception\RequestException;

class TrustedServiceMiddleware
{
    protected AuthServiceClient $authServiceClient;

    public function __construct(AuthServiceClient $authServiceClient)
    {
        $this->authServiceClient = $authServiceClient;
    }

    /**
     * Handle an incoming request - validates that the calling service is trusted by the target service
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string|null  $targetServiceSlug  The service slug that should trust the calling service
     * @return mixed
     */
    public function handle(Request $request, Closure $next, ?string $targetServiceSlug = null)
    {
        $callingServiceKey = $this->getServiceKeyFromRequest($request);

        if (!$callingServiceKey) {
            return response()->json([
                'success' => false,
                'message' => 'Calling service key is required',
                'errors' => ['calling_service_key' => 'Missing calling service API key']
            ], Response::HTTP_UNAUTHORIZED);
        }

        // If no target service specified, try to get it from request
        if (!$targetServiceSlug) {
            $targetServiceSlug = $request->input('target_service') ?? $request->route('service');
        }

        if (!$targetServiceSlug) {
            return response()->json([
                'success' => false,
                'message' => 'Target service not specified',
                'errors' => ['target_service' => 'Target service slug is required']
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($callingServiceKey === config('authservice.auth_service_api_key') &&
            $targetServiceSlug === config('authservice.service_slug')) {
            // Allow internal calls from the same service without further checks
            $request->merge([
                'calling_service' => [
                    'id' => null,
                    'name' => config('app.name'),
                    'slug' => config('authservice.service_slug')
                ],
                'target_service' => [
                    'id' => null,
                    'name' => config('app.name'),
                    'slug' => config('authservice.service_slug')
                ],
                'trust_permissions' => [],
                'calling_service_key' => $callingServiceKey
            ]);
            return $next($request);
        }

        try {
            $trustCheckResult = $this->authServiceClient->checkTrust([
                'calling_service_key' => $callingServiceKey,
                'target_service_slug' => $targetServiceSlug
            ]);

            if (!($trustCheckResult['is_trusted'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service trust relationship not found',
                    'errors' => ['trust' => 'Calling service is not trusted by target service']
                ], Response::HTTP_FORBIDDEN);
            }

            // Add trust data to the request for access in controllers
            $request->merge([
                'calling_service' => $trustCheckResult['calling_service'] ?? null,
                'target_service' => $trustCheckResult['target_service'] ?? null,
                'trust_permissions' => $trustCheckResult['permissions'] ?? [],
                'calling_service_key' => $callingServiceKey
            ]);

            return $next($request);

        } catch (RequestException $e) {
            Log::warning('Trusted Service middleware validation failed', [
                'calling_service_key' => substr($callingServiceKey, 0, 20) . '...',
                'target_service_slug' => $targetServiceSlug,
                'error' => $e->getMessage(),
                'status_code' => $e->getResponse() ? $e->getResponse()->getStatusCode() : null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Trust validation unavailable',
                'errors' => ['service' => 'Unable to validate service trust relationship']
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Exception $e) {
            Log::error('Trusted Service middleware error', [
                'calling_service_key' => substr($callingServiceKey, 0, 20) . '...',
                'target_service_slug' => $targetServiceSlug,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Trust validation failed',
                'errors' => ['trust' => 'Internal trust validation error']
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Extract service key from request
     */
    protected function getServiceKeyFromRequest(Request $request): ?string
    {
        // Check Authorization header with Bearer prefix (for service-to-service calls)
        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        // Check X-Service-Key header
        $serviceKey = $request->header('X-Service-Key');
        if ($serviceKey) {
            return $serviceKey;
        }

        // Check for calling_service_key in request parameters
        return $request->input('calling_service_key');
    }
}
