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
     * Generate a landing page session for authentication
     */
    public function generateLanding(string $action, string $callbackUrl, array $options = []): array
    {
        try {
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

            $response = $this->client->post($this->buildApiUrl('landing/generate'), [
                'json' => $payload
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            Log::error('Auth Service generate landing failed', [
                'action' => $action,
                'error' => $e->getMessage(),
                'response' => $e->getResponse() ? $e->getResponse()->getBody()->getContents() : null
            ]);

            throw $e;
        }
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
        try {
            $response = $this->client->get($this->buildApiUrl("landing/{$sessionId}/status"));

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            Log::error('Auth Service get landing status failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
                'response' => $e->getResponse() ? $e->getResponse()->getBody()->getContents() : null
            ]);

            throw $e;
        }
    }

    /**
     * Get authenticated user information
     */
    public function me(string $token): array
    {
        try {
            $response = $this->client->get($this->buildApiUrl('auth/me'), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ]
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            Log::error('Auth Service me failed', [
                'error' => $e->getMessage(),
                'response' => $e->getResponse() ? $e->getResponse()->getBody()->getContents() : null
            ]);

            throw $e;
        }
    }

    /**
     * Logout user
     */
    public function logout(string $token): array
    {
        try {
            $response = $this->client->post($this->buildApiUrl('auth/logout'), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ]
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            Log::warning('Auth Service logout failed', [
                'error' => $e->getMessage(),
                'response' => $e->getResponse() ? $e->getResponse()->getBody()->getContents() : null
            ]);

            // Don't throw - logout should always succeed locally even if service fails
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Check trust relationship between services
     */
    public function checkTrust(array $trustData): array
    {
        try {
            $response = $this->client->post($this->buildApiUrl('services/check-trust'), [
                'json' => $trustData
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            Log::error('Auth Service check trust failed', [
                'error' => $e->getMessage(),
                'response' => $e->getResponse() ? $e->getResponse()->getBody()->getContents() : null
            ]);

            throw $e;
        }
    }
}
