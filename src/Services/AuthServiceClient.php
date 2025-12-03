<?php

namespace AuthService\Helper\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class AuthServiceClient
{
    protected Client $client;
    protected string $baseUrl;
    protected string $apiKey;
    protected array $defaultHeaders;

    public function __construct()
    {
        $this->baseUrl = config('authservice.auth_service_base_url');
        $this->apiKey = config('authservice.auth_service_api_key');

        // Store default headers as class property for reliable access
        // NOTE: Auth service expects X-API-Key header, not X-Service-Key
        $this->defaultHeaders = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-API-Key' => $this->apiKey,
        ];

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => config('authservice.timeout', 5),
            'connect_timeout' => config('authservice.connect_timeout', 2),
        ]);
    }

    /**
     * Build API URL
     */
    protected function buildApiUrl(string $endpoint): string
    {
        return rtrim($this->baseUrl, '/') . '/api/v1/' . ltrim($endpoint, '/');
    }

    /**
     * Generic HTTP request method with centralized error handling
     *
     * @param string $method HTTP method (GET, POST, PUT, PATCH, DELETE)
     * @param string $endpoint API endpoint
     * @param array $options Request options (headers, query, json, auth_token, throw, log_context)
     * @return array Decoded JSON response
     * @throws RequestException
     */
    protected function request(string $method, string $endpoint, array $options = []): array
    {
        try {
            $method = strtoupper($method);
            $requestOptions = [];

            // Always start with default headers to ensure X-Service-Key is included
            $requestOptions['headers'] = $this->defaultHeaders;

            // Merge custom headers if provided
            if (isset($options['headers'])) {
                $requestOptions['headers'] = array_merge(
                    $requestOptions['headers'],
                    $options['headers']
                );
            }

            // Add Bearer token if provided
            if (isset($options['auth_token'])) {
                $requestOptions['headers']['Authorization'] = 'Bearer ' . $options['auth_token'];
            }

            // Add query parameters if provided
            if (isset($options['query'])) {
                $requestOptions['query'] = $options['query'];
            }

            // Add JSON body if provided
            if (isset($options['json'])) {
                $requestOptions['json'] = $options['json'];
            }

            // Make the request
            $response = $this->client->request($method, $this->buildApiUrl($endpoint), $requestOptions);

            // Decode and return JSON response
            return json_decode($response->getBody()->getContents(), true);

        } catch (RequestException $e) {
            $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : null;
            $responseBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : null;

            $logContext = array_merge(
                [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                    'status_code' => $statusCode,
                    'response' => $responseBody
                ],
                $options['log_context'] ?? []
            );

            // Add specific hint for 401 errors
            if ($statusCode === 401) {
                $logContext['hint'] = 'Check that AUTH_SERVICE_API_KEY is set correctly';
            }

            Log::error('Auth Service request failed', $logContext);

            // Check if we should throw the exception
            if ($options['throw'] ?? true) {
                throw $e;
            }

            // Return error response without throwing
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'status_code' => $statusCode
            ];
        }
    }

    /**
     * Send GET request
     *
     * @param string $endpoint API endpoint
     * @param array $options Request options (headers, query, auth_token, throw, log_context)
     * @return array Decoded JSON response
     */
    public function get(string $endpoint, array $options = []): array
    {
        return $this->request('GET', $endpoint, $options);
    }

    /**
     * Send POST request
     *
     * @param string $endpoint API endpoint
     * @param array $data Request body data
     * @param array $options Request options (headers, query, auth_token, throw, log_context)
     * @return array Decoded JSON response
     */
    public function post(string $endpoint, array $data = [], array $options = []): array
    {
        if (!empty($data) && !isset($options['json'])) {
            $options['json'] = $data;
        }

        return $this->request('POST', $endpoint, $options);
    }

    /**
     * Send PUT request
     *
     * @param string $endpoint API endpoint
     * @param array $data Request body data
     * @param array $options Request options (headers, query, auth_token, throw, log_context)
     * @return array Decoded JSON response
     */
    public function put(string $endpoint, array $data = [], array $options = []): array
    {
        if (!empty($data) && !isset($options['json'])) {
            $options['json'] = $data;
        }

        return $this->request('PUT', $endpoint, $options);
    }

    /**
     * Send PATCH request
     *
     * @param string $endpoint API endpoint
     * @param array $data Request body data
     * @param array $options Request options (headers, query, auth_token, throw, log_context)
     * @return array Decoded JSON response
     */
    public function patch(string $endpoint, array $data = [], array $options = []): array
    {
        if (!empty($data) && !isset($options['json'])) {
            $options['json'] = $data;
        }

        return $this->request('PATCH', $endpoint, $options);
    }

    /**
     * Send DELETE request
     *
     * @param string $endpoint API endpoint
     * @param array $options Request options (headers, query, auth_token, throw, log_context)
     * @return array Decoded JSON response
     */
    public function delete(string $endpoint, array $options = []): array
    {
        return $this->request('DELETE', $endpoint, $options);
    }

    /**
     * Generate a landing page session for authentication
     */
    public function generateLanding(string $action, string $callbackUrl, array $options = []): array
    {
        $payload = [
            'action' => $action,
            'callback_url' => $callbackUrl,
        ];

        // Add optional parameters
        if (isset($options['metadata'])) {
            $payload['metadata'] = $options['metadata'];
        }
        if (isset($options['expires_in'])) {
            $payload['expires_in'] = $options['expires_in'];
        }
        if (isset($options['roles'])) {
            $payload['roles'] = $options['roles'];
        }

        return $this->post('landing/generate', $payload, [
            'log_context' => ['action' => $action]
        ]);
    }

    /**
     * Generate a role-restricted login landing page session
     */
    public function generateRoleRestrictedLoginLanding(string $callbackUrl, array $roles, array $options = []): array
    {
        $options['roles'] = $roles;
        return $this->generateLanding('login', $callbackUrl, $options);
    }

    /**
     * Get landing page session status
     */
    public function getLandingStatus(string $sessionId): array
    {
        return $this->get("landing/{$sessionId}/status", [
            'log_context' => ['session_id' => $sessionId]
        ]);
    }

    /**
     * Get authenticated user information
     */
    public function me(string $token): array
    {
        return $this->get('auth/me', [
            'auth_token' => $token,
            'log_context' => ['operation' => 'get_user_profile']
        ]);
    }

    /**
     * Logout user
     */
    public function logout(string $token): array
    {
        return $this->post('auth/logout', [], [
            'auth_token' => $token,
            'throw' => false, // Don't throw - logout should always succeed locally
            'log_context' => ['operation' => 'logout_user']
        ]);
    }

    /**
     * Check trust relationship between services
     */
    public function checkTrust(array $trustData): array
    {
        return $this->post('services/check-trust', $trustData, [
            'log_context' => ['operation' => 'check_service_trust']
        ]);
    }

    /**
     * Validate a service trust API key.
     *
     * @param string $trustKey The trust key to validate
     * @return array Validation result with service details
     * @throws \Exception If the request fails
     */
    public function validateTrustKey(string $trustKey): array
    {
        return $this->post('services/validate-trust-key', [
            'trust_key' => $trustKey
        ], [
            'log_context' => ['operation' => 'validate_trust_key']
        ]);
    }

    /**
     * Create a new trust key for service-to-service communication.
     *
     * @param string $serviceId The service creating the key
     * @param string $trustedServiceId The service receiving trust
     * @param array $keyData Optional key data (name, expires_at, permissions)
     * @return array The created trust key data (includes plaintext key only on creation)
     * @throws \Exception If the request fails
     */
    public function createTrustKey(string $serviceId, string $trustedServiceId, array $keyData = []): array
    {
        return $this->post("services/{$serviceId}/trusted/{$trustedServiceId}/keys", $keyData, [
            'log_context' => [
                'operation' => 'create_trust_key',
                'service_id' => $serviceId,
                'trusted_service_id' => $trustedServiceId
            ]
        ]);
    }

    /**
     * Get all trust keys for a service-to-service relationship.
     *
     * @param string $serviceId The service ID
     * @param string $trustedServiceId The trusted service ID
     * @param array $filters Optional filters (is_active, include_expired)
     * @return array List of trust keys (without full key values)
     * @throws \Exception If the request fails
     */
    public function getTrustKeys(string $serviceId, string $trustedServiceId, array $filters = []): array
    {
        return $this->get("services/{$serviceId}/trusted/{$trustedServiceId}/keys", [
            'query' => $filters,
            'log_context' => [
                'operation' => 'get_trust_keys',
                'service_id' => $serviceId,
                'trusted_service_id' => $trustedServiceId
            ]
        ]);
    }

    /**
     * Revoke a trust key.
     *
     * @param int $trustKeyId The trust key ID to revoke
     * @return array Revocation result
     * @throws \Exception If the request fails
     */
    public function revokeTrustKey(int $trustKeyId): array
    {
        return $this->delete("trust-keys/{$trustKeyId}", [
            'log_context' => [
                'operation' => 'revoke_trust_key',
                'trust_key_id' => $trustKeyId
            ]
        ]);
    }

    /**
     * Get users with filters and pagination
     */
    public function getUsers(array $params = []): array
    {
        return $this->get('users', [
            'query' => $params,
            'log_context' => ['operation' => 'get_users']
        ]);
    }

    /**
     * Get a specific user by UUID
     */
    public function getUserByUuid(string $uuid): array
    {
        return $this->get("users/{$uuid}", [
            'log_context' => ['operation' => 'get_user', 'user_uuid' => $uuid]
        ]);
    }

    /**
     * Create a new user
     */
    public function createUser(array $data): array
    {
        return $this->post('users', $data, [
            'log_context' => ['operation' => 'create_user']
        ]);
    }

    /**
     * Update a user by UUID
     */
    public function updateUser(string $uuid, array $data): array
    {
        return $this->put("users/{$uuid}", $data, [
            'log_context' => ['operation' => 'update_user', 'user_uuid' => $uuid]
        ]);
    }

    /**
     * Delete a user by UUID
     */
    public function deleteUser(string $uuid): array
    {
        return $this->delete("users/{$uuid}", [
            'log_context' => ['operation' => 'delete_user', 'user_uuid' => $uuid]
        ]);
    }

    /**
     * Find users by custom conditions
     */
    public function findUsersBy(array $conditions): array
    {
        return $this->post('users/find-by', [
            'conditions' => $conditions
        ], [
            'log_context' => ['operation' => 'find_users_by']
        ]);
    }

    /**
     * Search users across multiple fields
     */
    public function searchUsers(string $query): array
    {
        return $this->get('users/search', [
            'query' => ['q' => $query],
            'log_context' => ['operation' => 'search_users', 'search_query' => $query]
        ]);
    }

    /**
     * Get user count with optional filters
     */
    public function getUserCount(array $filters = []): array
    {
        return $this->get('users/count', [
            'query' => $filters,
            'log_context' => ['operation' => 'get_user_count']
        ]);
    }

    /**
     * Bulk update multiple users
     */
    public function bulkUpdateUsers(array $userIds, array $data): array
    {
        return $this->post('users/bulk-update', [
            'user_ids' => $userIds,
            'data' => $data
        ], [
            'log_context' => ['operation' => 'bulk_update_users', 'count' => count($userIds)]
        ]);
    }

    /**
     * Bulk delete multiple users
     */
    public function bulkDeleteUsers(array $userIds): array
    {
        return $this->post('users/bulk-delete', [
            'user_ids' => $userIds
        ], [
            'log_context' => ['operation' => 'bulk_delete_users', 'count' => count($userIds)]
        ]);
    }

    /**
     * Get user's active sessions
     */
    public function getUserSessions(string $uuid): array
    {
        return $this->get("users/{$uuid}/sessions", [
            'log_context' => ['operation' => 'get_user_sessions', 'user_uuid' => $uuid]
        ]);
    }

    /**
     * Get user's roles across all services
     */
    public function getUserRoles(string $uuid): array
    {
        return $this->get("users/{$uuid}/roles", [
            'log_context' => ['operation' => 'get_user_roles', 'user_uuid' => $uuid]
        ]);
    }

    /**
     * Get user's service metadata
     */
    public function getUserMetadata(string $uuid): array
    {
        return $this->get("users/{$uuid}/metadata", [
            'log_context' => ['operation' => 'get_user_metadata', 'user_uuid' => $uuid]
        ]);
    }

    /**
     * Get all admin users
     */
    public function getAdminUsers(): array
    {
        return $this->get('users/admins', [
            'log_context' => ['operation' => 'get_admin_users']
        ]);
    }

    /**
     * Get recently active users
     */
    public function getRecentlyActiveUsers(int $days = 7, int $limit = 50): array
    {
        return $this->get('users/recent', [
            'query' => [
                'days' => $days,
                'limit' => $limit
            ],
            'log_context' => ['operation' => 'get_recently_active_users']
        ]);
    }

    /**
     * Get users with unverified emails
     */
    public function getUnverifiedUsers(): array
    {
        return $this->get('users/unverified', [
            'log_context' => ['operation' => 'get_unverified_users']
        ]);
    }

    /**
     * Export users data
     */
    public function exportUsers(string $format = 'json'): array
    {
        return $this->get('users/export', [
            'query' => ['format' => $format],
            'log_context' => ['operation' => 'export_users', 'format' => $format]
        ]);
    }

    /**
     * Get all accounts in the current multi-account session
     */
    public function getSessionAccounts(string $token): array
    {
        return $this->get('auth/session-accounts', [
            'auth_token' => $token,
            'log_context' => ['operation' => 'get_session_accounts']
        ]);
    }

    /**
     * Switch to a different account in the current session
     */
    public function switchAccount(string $token, string $userUuid): array
    {
        return $this->post('auth/switch-account', [
            'user_uuid' => $userUuid
        ], [
            'auth_token' => $token,
            'log_context' => ['operation' => 'switch_account', 'user_uuid' => $userUuid]
        ]);
    }

    /**
     * Add another account to the current session via credentials
     */
    public function addAccount(string $token, string $email, string $password): array
    {
        return $this->post('auth/add-account', [
            'email' => $email,
            'password' => $password
        ], [
            'auth_token' => $token,
            'log_context' => ['operation' => 'add_account', 'email' => $email]
        ]);
    }

    /**
     * Create a landing session for adding another account
     */
    public function createAddAccountSession(string $token, string $callbackUrl, ?array $roles = null): array
    {
        $payload = [
            'callback_url' => $callbackUrl
        ];

        if ($roles && count($roles) > 0) {
            $payload['roles'] = $roles;
        }

        return $this->post('auth/create-add-account-session', $payload, [
            'auth_token' => $token,
            'log_context' => ['operation' => 'create_add_account_session']
        ]);
    }

    /**
     * Remove an account from the current session
     */
    public function removeAccount(string $token, string $userUuid): array
    {
        return $this->delete("auth/remove-account/{$userUuid}", [
            'auth_token' => $token,
            'throw' => false, // Don't throw exception, return error response instead
            'log_context' => ['operation' => 'remove_account', 'user_uuid' => $userUuid]
        ]);
    }
}
