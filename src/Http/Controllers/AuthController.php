<?php

namespace AuthService\Helper\Http\Controllers;

use AuthService\Helper\Models\User;
use AuthService\Helper\Services\AuthServiceClient;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    protected AuthServiceClient $authServiceClient;

    public function __construct(AuthServiceClient $authServiceClient)
    {
        $this->authServiceClient = $authServiceClient;
    }

    /**
     * Check if user has required roles using service-scoped roles
     */
    private function hasRequiredRoles(array $userRoles, ?array $requiredRoles): bool
    {
        if (empty($requiredRoles)) {
            return true;
        }

        $serviceSlug = config('authservice.service_slug');

        // Build expanded roles list including service-scoped versions
        $expandedRequiredRoles = [];
        foreach ($requiredRoles as $role) {
            $expandedRequiredRoles[] = $role;
            $expandedRequiredRoles[] = $serviceSlug . ':' . $role;
        }

        // Add global admin roles
        $expandedRequiredRoles[] = 'super-admin';
        $expandedRequiredRoles[] = 'admin';

        return !empty(array_intersect($userRoles, $expandedRequiredRoles));
    }

    /**
     * Show the login page
     */
    public function showLogin()
    {
        // Check if user is already authenticated via guard
        if (Auth::guard('authservice')->check()) {
            $user = Auth::guard('authservice')->user();
            $requiredRoles = config('authservice.login_roles');

            // Check if user has required roles
            if ($this->hasRequiredRoles($user->getRoles(), $requiredRoles)) {
                $redirectUrl = config('authservice.redirect_after_login', '/dashboard');
                return redirect($redirectUrl)->with('info', 'You are already logged in');
            }
        }

        return view('authservice::auth.login');
    }

    /**
     * Generate a landing session for web authentication
     */
    public function generateLanding(Request $request)
    {
        try {
            $request->validate([
                'action' => 'required|string|in:login,register,reset-password,otp-verification',
                'callback_url' => 'required|url',
                'metadata' => 'sometimes|array'
            ]);

            $action = $request->input('action');
            $callbackUrl = $request->input('callback_url');
            $metadata = $request->input('metadata', []);

            // Add timestamp to metadata
            $metadata['timestamp'] = now()->timestamp;
            $metadata['service'] = config('authservice.service_slug');

            // Check if role-restricted login is required
            $requiredRoles = config('authservice.login_roles');
            if (!empty($requiredRoles) && $action === 'login') {
                $response = $this->authServiceClient->generateRoleRestrictedLoginLanding(
                    $callbackUrl,
                    $requiredRoles,
                    ['metadata' => $metadata]
                );
            } else {
                // Use the regular landing generation for other actions
                $response = $this->authServiceClient->generateLanding($action, $callbackUrl, [
                    'metadata' => $metadata
                ]);
            }

            if ($response['success'] ?? false) {
                // Handle different possible response structures
                $responseData = $response['data'] ?? $response;

                return response()->json([
                    'success' => true,
                    'data' => [
                        'session_id' => $responseData['session_id'] ?? $responseData['id'] ?? null,
                        'auth_url' => $responseData['auth_url'] ?? $responseData['url'] ?? $responseData['landing_url'] ?? null,
                        'expires_at' => $responseData['expires_at'] ?? $responseData['expires'] ?? null
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $response['message'] ?? 'Failed to generate authentication session'
                ], 400);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication service error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle callback from auth service
     */
    public function handleCallback(Request $request)
    {
        try {
            $sessionId = $request->input('session_id');
            $token = $request->input('token');
            $status = $request->input('status');

            if (!$sessionId) {
                return redirect()->route('auth.login')
                    ->with('error', 'Invalid authentication session');
            }

            // Use token from callback URL if provided
            if ($token && $status === 'success') {
                $authToken = $token;
            } else {
                // Fallback: Check the landing session status
                $statusResponse = $this->authServiceClient->getLandingStatus($sessionId);

                if (!($statusResponse['success'] ?? false)) {
                    return redirect()->route('auth.login')
                        ->with('error', 'Authentication session expired or invalid');
                }

                $landingData = $statusResponse['data'];

                // Check if session is expired
                if ($landingData['is_expired'] ?? false) {
                    return redirect()->route('auth.login')
                        ->with('error', 'Authentication session has expired');
                }

                // Check if authentication was completed
                if (!($landingData['is_used'] ?? false) || !($landingData['completed_at'] ?? false)) {
                    return redirect()->route('auth.login')
                        ->with('error', 'Authentication not completed');
                }

                // Get token from result
                $result = $landingData['result'] ?? [];
                if (!isset($result['token'])) {
                    return redirect()->route('auth.login')
                        ->with('error', 'No authentication token received');
                }

                $authToken = $result['token'];
            }

            // Validate the token
            $userResponse = $this->authServiceClient->me($authToken);

            if (!($userResponse['success'] ?? false)) {
                $errorMessage = 'Token validation failed';
                if (isset($userResponse['message'])) {
                    $errorMessage .= ': ' . $userResponse['message'];
                }
                return redirect()->route('auth.login')
                    ->with('error', $errorMessage);
            }

            $userData = $userResponse['data'];

            // Create User instance and check roles
            $user = User::createFromSession($userData);
            $requiredRoles = config('authservice.login_roles');
            if (!$this->hasRequiredRoles($user->getRoles(), $requiredRoles)) {
                return redirect()->route('auth.login')
                    ->with('error', 'Access denied. You do not have the required roles to access this service.');
            }

            // Store user data in session and log in via guard
            $currentTime = now()->timestamp;
            session([
                'auth_user' => $userData,
                'auth_token' => $authToken,
                'login_time' => $currentTime,
                'last_activity' => $currentTime,
            ]);

            // Login via the authservice guard
            Auth::guard('authservice')->login($user);

            // Get redirect URL
            $redirectUrl = config('authservice.redirect_after_login', '/dashboard');

            // Return a view that stores token in localStorage before redirecting
            return view('authservice::auth.redirect-with-token', [
                'token' => $authToken,
                'redirectUrl' => $redirectUrl,
                'successMessage' => 'Successfully logged in'
            ]);

        } catch (\Exception $e) {
            return redirect()->route('auth.login')
                ->with('error', 'Authentication error: ' . $e->getMessage());
        }
    }

    /**
     * Handle logout
     */
    public function logout(Request $request)
    {
        try {
            $authToken = session('auth_token');

            if ($authToken) {
                // Logout from auth service
                $this->authServiceClient->logout($authToken);
            }

            // Logout via the authservice guard
            Auth::guard('authservice')->logout();

            // Clear session
            session()->forget(['auth_token', 'login_time', 'last_activity']);
            session()->flush();

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Successfully logged out'
                ]);
            }

            return redirect()->route('auth.login')
                ->with('success', 'Successfully logged out');

        } catch (\Exception $e) {
            // Logout via guard and clear session anyway
            Auth::guard('authservice')->logout();
            session()->flush();

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Logged out'
                ]);
            }

            return redirect()->route('auth.login')
                ->with('success', 'Logged out');
        }
    }
}
