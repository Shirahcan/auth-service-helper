<?php

namespace AuthService\Helper\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade for TrustedServiceClient
 *
 * This facade provides a convenient static interface to the TrustedServiceClient service,
 * enabling easy service-to-service communication using trust-based authentication via API keys.
 *
 * @method static \Illuminate\Http\Client\Response makeTrustedGetRequest(string $serviceSlug, string $endpoint, array $queryParams = [], array $additionalHeaders = [], ?string $bearerToken = null) Make a trusted GET request to another service
 * @method static \Illuminate\Http\Client\Response makeTrustedPostRequest(string $serviceSlug, string $endpoint, array $data = [], array $additionalHeaders = [], ?string $bearerToken = null) Make a trusted POST request to another service
 * @method static \Illuminate\Http\Client\Response makeTrustedPutRequest(string $serviceSlug, string $endpoint, array $data = [], array $additionalHeaders = [], ?string $bearerToken = null) Make a trusted PUT request to another service
 * @method static \Illuminate\Http\Client\Response makeTrustedPatchRequest(string $serviceSlug, string $endpoint, array $data = [], array $additionalHeaders = [], ?string $bearerToken = null) Make a trusted PATCH request to another service
 * @method static \Illuminate\Http\Client\Response makeTrustedDeleteRequest(string $serviceSlug, string $endpoint, array $data = [], array $additionalHeaders = [], ?string $bearerToken = null) Make a trusted DELETE request to another service
 * @method static \AuthService\Helper\Services\TrustedServiceClient setTimeout(int $seconds) Set the timeout for HTTP requests
 * @method static \AuthService\Helper\Services\TrustedServiceClient setRetries(int $retries, int $delayMs = 100) Set the number of retries and delay for failed requests
 * @method static \AuthService\Helper\Services\TrustedServiceClient throwOnError(bool $throw = true) Configure whether to throw exceptions on HTTP errors
 *
 * @see \AuthService\Helper\Services\TrustedServiceClient
 */
class TrustedService extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return \AuthService\Helper\Services\TrustedServiceClient::class;
    }
}
