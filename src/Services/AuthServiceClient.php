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

    public function __construct()
    {
        $this->baseUrl = config('authservice.auth_service_base_url');
        $this->apiKey = config('authservice.auth_service_api_key');

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => config('authservice.timeout', 30),
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Service-Key' => $this->apiKey,
            ],
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

            // Add custom headers if provided
            if (isset($options['headers'])) {
                $requestOptions['headers'] = array_merge(
                    $this->client->getConfig('headers') ?? [],
                    $options['headers']
                );
            }

            // Add query parameters if provided
            if (isset($options['query'])) {
                $requestOptions['query'] = $options['query'];
            }

            // Add JSON body if provided
            if (isset($options['json'])) {
                $requestOptions['json'] = $options['json'];
            }

            // Add Bearer token if provided
            if (isset($options['auth_token'])) {
                $requestOptions['headers'] = array_merge(
                    $requestOptions['headers'] ?? [],
                    ['Authorization' => 'Bearer ' . $options['auth_token']]
                );
            }

            // Make the request
            $response = $this->client->request($method, $this->buildApiUrl($endpoint), $requestOptions);

            // Decode and return JSON response
            return json_decode($response->getBody()->getContents(), true);

        } catch (RequestException $e) {
            $logContext = array_merge(
                [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                    'response' => $e->getResponse() ? $e->getResponse()->getBody()->getContents() : null
                ],
                $options['log_context'] ?? []
            );

            Log::error('Auth Service request failed', $logContext);

            // Check if we should throw the exception
            if ($options['throw'] ?? true) {
                throw $e;
            }

            // Return error response without throwing
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'status_code' => $e->getResponse() ? $e->getResponse()->getStatusCode() : null
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
}
