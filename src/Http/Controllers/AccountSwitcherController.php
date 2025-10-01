<?php

namespace AuthService\Helper\Http\Controllers;

use AuthService\Helper\Services\AuthServiceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AccountSwitcherController extends Controller
{
    protected AuthServiceClient $authServiceClient;

    public function __construct(AuthServiceClient $authServiceClient)
    {
        $this->authServiceClient = $authServiceClient;
    }

    /**
     * Get all accounts in the current session
     */
    public function getSessionAccounts(): JsonResponse
    {
        try {
            $token = session('auth_token');

            if (!$token) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'session' => [
                            'session_id' => null,
                            'accounts' => [],
                            'active_account' => null,
                            'primary_account' => null
                        ]
                    ]
                ]);
            }

            $response = $this->authServiceClient->getSessionAccounts($token);

            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch session accounts: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Switch to a different account in the session
     */
    public function switchAccount(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'user_uuid' => 'required|string'
            ]);

            $token = session('auth_token');
            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active session found'
                ], 401);
            }

            $response = $this->authServiceClient->switchAccount(
                $token,
                $request->input('user_uuid')
            );

            if ($response['success'] ?? false) {
                // Update Laravel session with new token if provided
                if (isset($response['data']['token'])) {
                    session([
                        'auth_token' => $response['data']['token'],
                        'auth_user' => $response['data']['user'] ?? session('auth_user'),
                        'last_activity' => now()->timestamp
                    ]);
                }

                return response()->json($response);
            }

            return response()->json($response, 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to switch account: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a landing session for adding another account
     */
    public function createAddAccountSession(Request $request): JsonResponse
    {
        try {
            $token = session('auth_token');

            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active session found'
                ], 401);
            }

            $callbackUrl = $request->input('callback_url', url()->current());
            $roles = $request->input('roles');

            // Parse roles if provided as string
            if (is_string($roles)) {
                $roles = array_filter(explode(' ', $roles));
            }

            $response = $this->authServiceClient->createAddAccountSession(
                $token,
                $callbackUrl,
                $roles
            );

            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create add account session: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove an account from the session
     */
    public function removeAccount(string $uuid): JsonResponse
    {
        try {
            $token = session('auth_token');

            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active session found'
                ], 401);
            }

            $response = $this->authServiceClient->removeAccount($token, $uuid);

            if ($response['success'] ?? false) {
                // Update Laravel session with new token if provided
                if (isset($response['data']['token'])) {
                    session([
                        'auth_token' => $response['data']['token'],
                        'auth_user' => $response['data']['user'] ?? session('auth_user'),
                        'last_activity' => now()->timestamp
                    ]);
                }

                return response()->json($response);
            }

            // Log failed response from auth service
            \Log::error('Failed to remove account from auth service', [
                'uuid' => $uuid,
                'response' => $response,
                'status_code' => $response['status_code'] ?? null
            ]);

            // Provide helpful error message based on status code
            $statusCode = $response['status_code'] ?? 400;
            $errorMessage = $response['message'] ?? 'Failed to remove account';

            if ($statusCode === 500) {
                $errorMessage = 'Authentication service error. Please try again later or contact support.';
                \Log::critical('Auth service returned 500 error for remove account', [
                    'uuid' => $uuid,
                    'response' => $response
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $errorMessage
            ], $statusCode === 500 ? 503 : $statusCode); // Return 503 (Service Unavailable) for auth service errors
        } catch (\Exception $e) {
            \Log::error('Exception while removing account', [
                'uuid' => $uuid,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove account: ' . $e->getMessage()
            ], 500);
        }
    }
}
