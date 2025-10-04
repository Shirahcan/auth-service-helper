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

    /**
     * Sync session state from iframe widget
     *
     * This endpoint receives session state updates from the iframe widget
     * and synchronizes them with the Laravel session.
     */
    public function syncSession(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'is_authenticated' => 'required|boolean',
                'current_user' => 'nullable|array',
                'accounts' => 'nullable|array'
            ]);

            $isAuthenticated = $request->input('is_authenticated');
            $currentUser = $request->input('current_user');
            $accounts = $request->input('accounts', []);
            $shouldReload = false;

            // Get current session state
            $storedUser = session('auth_user');
            $wasAuthenticated = !empty($storedUser);

            if ($isAuthenticated && $currentUser) {
                // Check if account has changed (new account added or switched)
                // The iframe sends 'id' field, not 'uuid'
                $currentUserUuid = $currentUser['id'] ?? $currentUser['uuid'] ?? null;
                $storedUserUuid = $storedUser['id'] ?? $storedUser['uuid'] ?? null;

                // Reload if:
                // 1. No previous user in session (first login after page load)
                // 2. User UUID changed (account switched or new account added)
                if (!$storedUser || $currentUserUuid !== $storedUserUuid) {
                    $shouldReload = true;
                    \Log::debug('Account change detected, page reload needed', [
                        'previous_uuid' => $storedUserUuid,
                        'new_uuid' => $currentUserUuid
                    ]);
                }

                // Update session with current user and accounts
                session([
                    'auth_user' => $currentUser,
                    'auth_accounts' => $accounts,
                    'last_activity' => now()->timestamp
                ]);

                \Log::debug('Session synced from iframe widget', [
                    'user_uuid' => $currentUserUuid,
                    'account_count' => count($accounts),
                    'should_reload' => $shouldReload
                ]);
            } else {
                // Not authenticated - clear session
                // Reload if we were previously authenticated (logout occurred)
                if ($wasAuthenticated) {
                    $shouldReload = true;
                    \Log::debug('Logout detected, page reload needed', [
                        'previous_user' => $storedUser['uuid'] ?? null
                    ]);
                }

                session()->forget(['auth_user', 'auth_accounts']);

                \Log::debug('Session cleared from iframe widget sync');
            }

            return response()->json([
                'success' => true,
                'message' => 'Session synchronized successfully',
                'should_reload' => $shouldReload
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to sync session from iframe', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to sync session: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sync auth token from iframe widget
     *
     * This endpoint receives token refresh events from the iframe widget
     * and updates the Laravel session with the new token.
     */
    public function syncToken(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'token' => 'required|string'
            ]);

            $token = $request->input('token');

            // Update Laravel session with new token
            session([
                'auth_token' => $token,
                'last_activity' => now()->timestamp
            ]);

            \Log::debug('Auth token synced from iframe widget');

            return response()->json([
                'success' => true,
                'message' => 'Token synchronized successfully'
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to sync token from iframe', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to sync token: ' . $e->getMessage()
            ], 500);
        }
    }
}
