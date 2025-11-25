<?php

namespace AuthService\Helper\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Exception;

/**
 * TrustedServiceClient - Secure service-to-service communication using Service Trust API Keys
 *
 * This client enables trusted services to communicate with each other by automatically
 * injecting trust keys, API keys, and authorization headers based on configuration.
 */
class TrustedServiceClient
{
    /**
     * HTTP request timeout in seconds
     */
    protected int $timeout = 30;

    /**
     * Number of retry attempts on transient failures
     */
    protected int $retries = 2;

    /**
     * Delay between retry attempts in milliseconds
     */
    protected int $retryDelay = 100;

    /**
     * Whether to throw exceptions on HTTP errors
     */
    protected bool $throwOnError = true;

    /**
     * Set the HTTP request timeout
     *
     * @param int $seconds Timeout in seconds
     * @return self
     */
    public function setTimeout(int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }

    /**
     * Set retry configuration
     *
     * @param int $retries Number of retry attempts
     * @param int $delayMs Delay between retries in milliseconds
     * @return self
     */
    public function setRetries(int $retries, int $delayMs = 100): self
    {
        $this->retries = $retries;
        $this->retryDelay = $delayMs;
        return $this;
    }

    /**
     * Set whether to throw exceptions on HTTP errors
     *
     * @param bool $throw Whether to throw on error
     * @return self
     */
    public function throwOnError(bool $throw = true): self
    {
        $this->throwOnError = $throw;
        return $this;
    }

    /**
     * Make a trusted GET request to another service
     *
     * @param string $serviceSlug The target service slug (e.g., 'consultancy-service')
     * @param string $endpoint The API endpoint (e.g., '/api/v1/clients')
     * @param array $queryParams Query string parameters
     * @param array $additionalHeaders Additional headers to merge
     * @param string|null $bearerToken Optional bearer token to include
     * @return Response
     * @throws Exception
     */
    public function makeTrustedGetRequest(
        string $serviceSlug,
        string $endpoint,
        array $queryParams = [],
        array $additionalHeaders = [],
        ?string $bearerToken = null
    ): Response {
        $url = $this->buildFullUrl($serviceSlug, $endpoint);
        $headers = $this->buildHeaders($serviceSlug, $additionalHeaders, $bearerToken);

        Log::info('TrustedServiceClient: Making GET request', [
            'service' => $serviceSlug,
            'url' => $url,
            'query_params' => $queryParams,
            'headers' => array_keys($headers),
        ]);

        try {
            $request = Http::withHeaders($headers)
                ->timeout($this->timeout)
                ->retry($this->retries, $this->retryDelay);

            if (!$this->throwOnError) {
                $request = $request->withoutThrow();
            }

            $response = $request->get($url, $queryParams);

            Log::info('TrustedServiceClient: GET request successful', [
                'service' => $serviceSlug,
                'status' => $response->status(),
                'url' => $url,
            ]);

            return $response;
        } catch (Exception $e) {
            Log::error('TrustedServiceClient: GET request failed', [
                'service' => $serviceSlug,
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Make a trusted POST request to another service
     *
     * @param string $serviceSlug The target service slug
     * @param string $endpoint The API endpoint
     * @param array $data Request body data
     * @param array $additionalHeaders Additional headers to merge
     * @param string|null $bearerToken Optional bearer token to include
     * @return Response
     * @throws Exception
     */
    public function makeTrustedPostRequest(
        string $serviceSlug,
        string $endpoint,
        array $data = [],
        array $additionalHeaders = [],
        ?string $bearerToken = null
    ): Response {
        $url = $this->buildFullUrl($serviceSlug, $endpoint);
        $headers = $this->buildHeaders($serviceSlug, $additionalHeaders, $bearerToken);

        Log::info('TrustedServiceClient: Making POST request', [
            'service' => $serviceSlug,
            'url' => $url,
            'data_keys' => array_keys($data),
            'headers' => array_keys($headers),
        ]);

        try {
            $request = Http::withHeaders($headers)
                ->timeout($this->timeout)
                ->retry($this->retries, $this->retryDelay);

            if (!$this->throwOnError) {
                $request = $request->withoutThrow();
            }

            $response = $request->post($url, $data);

            Log::info('TrustedServiceClient: POST request successful', [
                'service' => $serviceSlug,
                'status' => $response->status(),
                'url' => $url,
            ]);

            return $response;
        } catch (Exception $e) {
            Log::error('TrustedServiceClient: POST request failed', [
                'service' => $serviceSlug,
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Make a trusted PUT request to another service
     *
     * @param string $serviceSlug The target service slug
     * @param string $endpoint The API endpoint
     * @param array $data Request body data
     * @param array $additionalHeaders Additional headers to merge
     * @param string|null $bearerToken Optional bearer token to include
     * @return Response
     * @throws Exception
     */
    public function makeTrustedPutRequest(
        string $serviceSlug,
        string $endpoint,
        array $data = [],
        array $additionalHeaders = [],
        ?string $bearerToken = null
    ): Response {
        $url = $this->buildFullUrl($serviceSlug, $endpoint);
        $headers = $this->buildHeaders($serviceSlug, $additionalHeaders, $bearerToken);

        Log::info('TrustedServiceClient: Making PUT request', [
            'service' => $serviceSlug,
            'url' => $url,
            'data_keys' => array_keys($data),
            'headers' => array_keys($headers),
        ]);

        try {
            $request = Http::withHeaders($headers)
                ->timeout($this->timeout)
                ->retry($this->retries, $this->retryDelay);

            if (!$this->throwOnError) {
                $request = $request->withoutThrow();
            }

            $response = $request->put($url, $data);

            Log::info('TrustedServiceClient: PUT request successful', [
                'service' => $serviceSlug,
                'status' => $response->status(),
                'url' => $url,
            ]);

            return $response;
        } catch (Exception $e) {
            Log::error('TrustedServiceClient: PUT request failed', [
                'service' => $serviceSlug,
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Make a trusted PATCH request to another service
     *
     * @param string $serviceSlug The target service slug
     * @param string $endpoint The API endpoint
     * @param array $data Request body data
     * @param array $additionalHeaders Additional headers to merge
     * @param string|null $bearerToken Optional bearer token to include
     * @return Response
     * @throws Exception
     */
    public function makeTrustedPatchRequest(
        string $serviceSlug,
        string $endpoint,
        array $data = [],
        array $additionalHeaders = [],
        ?string $bearerToken = null
    ): Response {
        $url = $this->buildFullUrl($serviceSlug, $endpoint);
        $headers = $this->buildHeaders($serviceSlug, $additionalHeaders, $bearerToken);

        Log::info('TrustedServiceClient: Making PATCH request', [
            'service' => $serviceSlug,
            'url' => $url,
            'data_keys' => array_keys($data),
            'headers' => array_keys($headers),
        ]);

        try {
            $request = Http::withHeaders($headers)
                ->timeout($this->timeout)
                ->retry($this->retries, $this->retryDelay);

            if (!$this->throwOnError) {
                $request = $request->withoutThrow();
            }

            $response = $request->patch($url, $data);

            Log::info('TrustedServiceClient: PATCH request successful', [
                'service' => $serviceSlug,
                'status' => $response->status(),
                'url' => $url,
            ]);

            return $response;
        } catch (Exception $e) {
            Log::error('TrustedServiceClient: PATCH request failed', [
                'service' => $serviceSlug,
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Make a trusted DELETE request to another service
     *
     * @param string $serviceSlug The target service slug
     * @param string $endpoint The API endpoint
     * @param array $data Optional request body data
     * @param array $additionalHeaders Additional headers to merge
     * @param string|null $bearerToken Optional bearer token to include
     * @return Response
     * @throws Exception
     */
    public function makeTrustedDeleteRequest(
        string $serviceSlug,
        string $endpoint,
        array $data = [],
        array $additionalHeaders = [],
        ?string $bearerToken = null
    ): Response {
        $url = $this->buildFullUrl($serviceSlug, $endpoint);
        $headers = $this->buildHeaders($serviceSlug, $additionalHeaders, $bearerToken);

        Log::info('TrustedServiceClient: Making DELETE request', [
            'service' => $serviceSlug,
            'url' => $url,
            'data_keys' => array_keys($data),
            'headers' => array_keys($headers),
        ]);

        try {
            $request = Http::withHeaders($headers)
                ->timeout($this->timeout)
                ->retry($this->retries, $this->retryDelay);

            if (!$this->throwOnError) {
                $request = $request->withoutThrow();
            }

            $response = $request->delete($url, $data);

            Log::info('TrustedServiceClient: DELETE request successful', [
                'service' => $serviceSlug,
                'status' => $response->status(),
                'url' => $url,
            ]);

            return $response;
        } catch (Exception $e) {
            Log::error('TrustedServiceClient: DELETE request failed', [
                'service' => $serviceSlug,
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Build the full URL for the target service
     *
     * @param string $serviceSlug The target service slug
     * @param string $endpoint The API endpoint
     * @return string Full URL
     * @throws Exception If service base URL is not configured
     */
    protected function buildFullUrl(string $serviceSlug, string $endpoint): string
    {
        $baseUrl = $this->getServiceBaseUrl($serviceSlug);

        // Ensure baseUrl doesn't end with slash and endpoint starts with slash
        $baseUrl = rtrim($baseUrl, '/');
        $endpoint = '/' . ltrim($endpoint, '/');

        return $baseUrl . $endpoint;
    }

    /**
     * Build headers for the request including trust key, API key, and bearer token
     *
     * @param string $serviceSlug The target service slug
     * @param array $additionalHeaders Additional headers to merge
     * @param string|null $bearerToken Optional bearer token
     * @return array Complete headers array
     * @throws Exception If trust key is not configured
     */
    protected function buildHeaders(
        string $serviceSlug,
        array $additionalHeaders = [],
        ?string $bearerToken = null
    ): array {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        // Add trust key (required)
        $trustKey = $this->getTrustKeyForService($serviceSlug);
        if (!$trustKey) {
            throw new Exception(
                "Trust key not found for service '{$serviceSlug}'. " .
                "Please configure it in config/authservice.php or set " .
                $this->normalizeSlugForEnv($serviceSlug) . "_TRUST_KEY environment variable."
            );
        }
        $headers['X-Trust-Key'] = $trustKey;

        // Add API key (optional)
        $apiKey = $this->getApiKeyForService($serviceSlug);
        if ($apiKey) {
            $headers['X-API-Key'] = $apiKey;
        }

        // Add bearer token if provided
        if ($bearerToken) {
            $headers['Authorization'] = 'Bearer ' . $bearerToken;
        }

        // Merge additional headers (they can override defaults)
        return array_merge($headers, $additionalHeaders);
    }

    /**
     * Get the trust key for a service using cascade pattern
     *
     * @param string $serviceSlug The target service slug
     * @return string|null Trust key or null if not found
     */
    protected function getTrustKeyForService(string $serviceSlug): ?string
    {
        // Try config first: config('authservice.trust_keys.{service_slug}')
        $trustKey = config("authservice.trust_keys.{$serviceSlug}");

        if ($trustKey) {
            return $trustKey;
        }

        // Try environment variable: {NORMALIZED_SLUG}_TRUST_KEY
        $envKey = $this->normalizeSlugForEnv($serviceSlug) . '_TRUST_KEY';
        $trustKey = env($envKey);

        return $trustKey ?: null;
    }

    /**
     * Get the API key for a service using cascade pattern
     *
     * @param string $serviceSlug The target service slug
     * @return string|null API key or null if not found (no exception thrown)
     */
    protected function getApiKeyForService(string $serviceSlug): ?string
    {
        // Try config first: config('authservice.api_keys.{service_slug}')
        $apiKey = config("authservice.api_keys.{$serviceSlug}");

        if ($apiKey) {
            return $apiKey;
        }

        // Try environment variable: {NORMALIZED_SLUG}_API_KEY
        $envKey = $this->normalizeSlugForEnv($serviceSlug) . '_API_KEY';
        $apiKey = env($envKey);

        return $apiKey ?: null;
    }

    /**
     * Get the base URL for a service using cascade pattern
     *
     * @param string $serviceSlug The target service slug
     * @return string Service base URL
     * @throws Exception If service URL is not configured
     */
    protected function getServiceBaseUrl(string $serviceSlug): string
    {
        // Try config first: config('authservice.service_urls.{service_slug}')
        $serviceUrl = config("authservice.service_urls.{$serviceSlug}");

        if ($serviceUrl) {
            return $serviceUrl;
        }

        // Try environment variable: {NORMALIZED_SLUG}_SERVICE_URL
        $envKey = $this->normalizeSlugForEnv($serviceSlug) . '_SERVICE_URL';
        $serviceUrl = env($envKey);

        if (!$serviceUrl) {
            throw new Exception(
                "Service URL not found for service '{$serviceSlug}'. " .
                "Please configure it in config/authservice.php or set " .
                $envKey . " environment variable."
            );
        }

        return $serviceUrl;
    }

    /**
     * Normalize a service slug to environment variable format
     *
     * Examples:
     * - 'consultancy-service' -> 'CONSULTANCY_SERVICE'
     * - 'ConsultancyService' -> 'CONSULTANCY_SERVICE'
     * - 'consultancy_service' -> 'CONSULTANCY_SERVICE'
     *
     * @param string $slug Service slug to normalize
     * @return string Normalized slug in UPPER_SNAKE_CASE
     */
    protected function normalizeSlugForEnv(string $slug): string
    {
        // Convert PascalCase/camelCase to snake_case
        // Insert underscore before uppercase letters that follow lowercase letters or numbers
        $snakeCase = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $slug);

        // Replace hyphens with underscores (for kebab-case)
        $snakeCase = str_replace('-', '_', $snakeCase);

        // Convert to uppercase
        return strtoupper($snakeCase);
    }
}
