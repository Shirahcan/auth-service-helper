<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use AuthService\Helper\Services\TrustedServiceClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\PendingRequest;
use Mockery;
use Exception;
use GuzzleHttp\Psr7\Response as Psr7Response;

class TrustedServiceClientTest extends TestCase
{
    protected TrustedServiceClient $client;
    protected $httpMock;
    protected $configMock;
    protected $logMock;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock Config facade
        $this->configMock = Mockery::mock('alias:Illuminate\Support\Facades\Config');

        // Set up default test configuration
        $this->configMock->shouldReceive('get')
            ->with('authservice.trust_keys.test-service', Mockery::any())
            ->andReturn('test-trust-key-123')
            ->byDefault();

        $this->configMock->shouldReceive('get')
            ->with('authservice.service_urls.test-service', Mockery::any())
            ->andReturn('https://test-service.local')
            ->byDefault();

        $this->configMock->shouldReceive('get')
            ->with('authservice.api_keys.test-service', Mockery::any())
            ->andReturn('test-api-key-456')
            ->byDefault();

        // Mock Http facade
        $this->httpMock = Mockery::mock('alias:Illuminate\Support\Facades\Http');

        // Mock Log facade to prevent errors
        $this->logMock = Mockery::mock('alias:Illuminate\Support\Facades\Log');
        $this->logMock->shouldReceive('info')->andReturnNull()->byDefault();
        $this->logMock->shouldReceive('error')->andReturnNull()->byDefault();

        $this->client = new TrustedServiceClient();
    }

    public function test_successful_get_request()
    {
        $mockResponse = $this->createMockResponse([
            'success' => true,
            'data' => [
                ['id' => 1, 'name' => 'Client A'],
                ['id' => 2, 'name' => 'Client B'],
            ],
        ], 200);

        $pendingRequest = $this->createMockPendingRequest($mockResponse);

        $this->httpMock->shouldReceive('withHeaders')
            ->once()
            ->andReturn($pendingRequest);

        $response = $this->client->makeTrustedGetRequest(
            'test-service',
            '/api/v1/clients'
        );

        $this->assertTrue($response->successful());
        $this->assertEquals(200, $response->status());
        $this->assertTrue($response->json('success'));
        $this->assertCount(2, $response->json('data'));
        $this->assertEquals('Client A', $response->json('data.0.name'));
    }

    public function test_trust_key_header_injection()
    {
        $mockResponse = $this->createMockResponse(['success' => true], 200);

        $pendingRequest = $this->createMockPendingRequest($mockResponse);

        $this->httpMock->shouldReceive('withHeaders')
            ->once()
            ->with(Mockery::on(function ($headers) {
                return isset($headers['X-Trust-Key'])
                    && $headers['X-Trust-Key'] === 'test-trust-key-123';
            }))
            ->andReturn($pendingRequest);

        $this->client->makeTrustedGetRequest(
            'test-service',
            '/api/test'
        );

        $this->assertTrue(true); // Assert passed if we got here
    }

    public function test_api_key_header_injection()
    {
        $mockResponse = $this->createMockResponse(['success' => true], 200);

        $pendingRequest = $this->createMockPendingRequest($mockResponse);

        $this->httpMock->shouldReceive('withHeaders')
            ->once()
            ->with(Mockery::on(function ($headers) {
                return isset($headers['X-API-Key'])
                    && $headers['X-API-Key'] === 'test-api-key-456';
            }))
            ->andReturn($pendingRequest);

        $this->client->makeTrustedGetRequest(
            'test-service',
            '/api/test'
        );

        $this->assertTrue(true); // Assert passed if we got here
    }

    public function test_bearer_token_injection()
    {
        $mockResponse = $this->createMockResponse(['success' => true], 200);

        $pendingRequest = $this->createMockPendingRequest($mockResponse);

        $bearerToken = 'user-access-token-xyz';

        $this->httpMock->shouldReceive('withHeaders')
            ->once()
            ->with(Mockery::on(function ($headers) use ($bearerToken) {
                return isset($headers['Authorization'])
                    && $headers['Authorization'] === 'Bearer ' . $bearerToken;
            }))
            ->andReturn($pendingRequest);

        $this->client->makeTrustedGetRequest(
            'test-service',
            '/api/test',
            [],
            [],
            $bearerToken
        );

        $this->assertTrue(true); // Assert passed if we got here
    }

    public function test_exception_when_trust_key_not_configured()
    {
        $this->markTestSkipped('Skipping test that requires env() mocking without Orchestra Testbench');

        $this->configMock->shouldReceive('get')
            ->with('authservice.trust_keys.test-service', Mockery::any())
            ->andReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Trust key not found for service 'test-service'");

        $this->client->makeTrustedGetRequest(
            'test-service',
            '/api/test'
        );
    }

    public function test_exception_when_service_url_not_configured()
    {
        $this->markTestSkipped('Skipping test that requires env() mocking without Orchestra Testbench');

        $this->configMock->shouldReceive('get')
            ->with('authservice.service_urls.test-service', Mockery::any())
            ->andReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Service URL not found for service 'test-service'");

        $this->client->makeTrustedGetRequest(
            'test-service',
            '/api/test'
        );
    }

    public function test_slug_normalization()
    {
        // Test various slug formats normalize to the same environment variable format
        $client = new TrustedServiceClient();

        // Use reflection to test the protected normalizeSlugForEnv method
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('normalizeSlugForEnv');
        $method->setAccessible(true);

        // Test kebab-case
        $this->assertEquals('CONSULTANCY_SERVICE', $method->invoke($client, 'consultancy-service'));

        // Test PascalCase
        $this->assertEquals('CONSULTANCY_SERVICE', $method->invoke($client, 'ConsultancyService'));

        // Test snake_case
        $this->assertEquals('CONSULTANCY_SERVICE', $method->invoke($client, 'consultancy_service'));

        // Test UPPER_SNAKE_CASE
        $this->assertEquals('CONSULTANCY_SERVICE', $method->invoke($client, 'CONSULTANCY_SERVICE'));

        // Test camelCase
        $this->assertEquals('CONSULTANCY_SERVICE', $method->invoke($client, 'consultancyService'));

        // Test single word
        $this->assertEquals('AUTH', $method->invoke($client, 'auth'));

        // Test with multiple hyphens
        $this->assertEquals('MY_LONG_SERVICE_NAME', $method->invoke($client, 'my-long-service-name'));
    }

    public function test_post_request_with_data()
    {
        $postData = [
            'name' => 'New Client',
            'email' => 'newclient@example.com',
            'phone' => '+1234567890',
        ];

        $mockResponse = $this->createMockResponse([
            'success' => true,
            'data' => [
                'id' => 3,
                'name' => 'New Client',
                'email' => 'newclient@example.com',
            ],
        ], 201);

        $pendingRequest = $this->createMockPendingRequest($mockResponse, 'post');

        $this->httpMock->shouldReceive('withHeaders')
            ->once()
            ->with(Mockery::on(function ($headers) {
                return isset($headers['Content-Type'])
                    && $headers['Content-Type'] === 'application/json';
            }))
            ->andReturn($pendingRequest);

        $response = $this->client->makeTrustedPostRequest(
            'test-service',
            '/api/v1/clients',
            $postData
        );

        $this->assertTrue($response->successful());
        $this->assertEquals(201, $response->status());
        $this->assertEquals('New Client', $response->json('data.name'));
    }

    public function test_custom_timeout()
    {
        $mockResponse = $this->createMockResponse(['success' => true], 200);

        $pendingRequest = $this->createMockPendingRequest($mockResponse);

        $this->httpMock->shouldReceive('withHeaders')
            ->once()
            ->andReturn($pendingRequest);

        $customTimeout = 60;
        $response = $this->client
            ->setTimeout($customTimeout)
            ->makeTrustedGetRequest('test-service', '/api/test');

        $this->assertTrue($response->successful());

        // Verify timeout was set using reflection
        $reflection = new \ReflectionClass($this->client);
        $property = $reflection->getProperty('timeout');
        $property->setAccessible(true);

        $this->assertEquals($customTimeout, $property->getValue($this->client));
    }

    public function test_disabled_throw_on_error()
    {
        $mockResponse = $this->createMockResponse([
            'error' => 'Resource not found',
        ], 404);

        $pendingRequest = $this->createMockPendingRequest($mockResponse, 'get', false);

        $this->httpMock->shouldReceive('withHeaders')
            ->once()
            ->andReturn($pendingRequest);

        // With throwOnError disabled, should not throw exception on 404
        $response = $this->client
            ->throwOnError(false)
            ->makeTrustedGetRequest('test-service', '/api/test');

        $this->assertFalse($response->successful());
        $this->assertEquals(404, $response->status());
        $this->assertEquals('Resource not found', $response->json('error'));
    }

    public function test_put_request_with_data()
    {
        $updateData = [
            'name' => 'Updated Client',
            'email' => 'updated@example.com',
        ];

        $mockResponse = $this->createMockResponse([
            'success' => true,
            'data' => [
                'id' => 5,
                'name' => 'Updated Client',
                'email' => 'updated@example.com',
            ],
        ], 200);

        $pendingRequest = $this->createMockPendingRequest($mockResponse, 'put');

        $this->httpMock->shouldReceive('withHeaders')
            ->once()
            ->with(Mockery::on(function ($headers) {
                return isset($headers['X-Trust-Key'])
                    && $headers['X-Trust-Key'] === 'test-trust-key-123';
            }))
            ->andReturn($pendingRequest);

        $response = $this->client->makeTrustedPutRequest(
            'test-service',
            '/api/v1/clients/5',
            $updateData
        );

        $this->assertTrue($response->successful());
        $this->assertEquals(200, $response->status());
        $this->assertEquals('Updated Client', $response->json('data.name'));
    }

    public function test_patch_request_with_data()
    {
        $patchData = [
            'email' => 'patched@example.com',
        ];

        $mockResponse = $this->createMockResponse([
            'success' => true,
            'data' => [
                'id' => 7,
                'name' => 'Original Name',
                'email' => 'patched@example.com',
            ],
        ], 200);

        $pendingRequest = $this->createMockPendingRequest($mockResponse, 'patch');

        $this->httpMock->shouldReceive('withHeaders')
            ->once()
            ->with(Mockery::on(function ($headers) {
                return isset($headers['X-Trust-Key'])
                    && $headers['X-Trust-Key'] === 'test-trust-key-123'
                    && isset($headers['X-API-Key'])
                    && $headers['X-API-Key'] === 'test-api-key-456';
            }))
            ->andReturn($pendingRequest);

        $response = $this->client->makeTrustedPatchRequest(
            'test-service',
            '/api/v1/clients/7',
            $patchData
        );

        $this->assertTrue($response->successful());
        $this->assertEquals(200, $response->status());
        $this->assertEquals('patched@example.com', $response->json('data.email'));
    }

    public function test_delete_request()
    {
        $mockResponse = $this->createMockResponse([
            'success' => true,
            'message' => 'Client deleted successfully',
        ], 200);

        $pendingRequest = $this->createMockPendingRequest($mockResponse, 'delete');

        $this->httpMock->shouldReceive('withHeaders')
            ->once()
            ->with(Mockery::on(function ($headers) {
                return isset($headers['X-Trust-Key'])
                    && $headers['X-Trust-Key'] === 'test-trust-key-123';
            }))
            ->andReturn($pendingRequest);

        $response = $this->client->makeTrustedDeleteRequest(
            'test-service',
            '/api/v1/clients/10'
        );

        $this->assertTrue($response->successful());
        $this->assertEquals(200, $response->status());
        $this->assertEquals('Client deleted successfully', $response->json('message'));
    }

    public function test_get_request_with_query_parameters()
    {
        $mockResponse = $this->createMockResponse([
            'success' => true,
            'data' => [],
            'pagination' => [
                'page' => 2,
                'per_page' => 50,
            ],
        ], 200);

        $queryParams = [
            'page' => 2,
            'per_page' => 50,
            'status' => 'active',
        ];

        $pendingRequest = $this->createMockPendingRequest($mockResponse, 'get', true, $queryParams);

        $this->httpMock->shouldReceive('withHeaders')
            ->once()
            ->andReturn($pendingRequest);

        $response = $this->client->makeTrustedGetRequest(
            'test-service',
            '/api/v1/clients',
            $queryParams
        );

        $this->assertTrue($response->successful());
    }

    public function test_additional_headers_are_merged()
    {
        $mockResponse = $this->createMockResponse(['success' => true], 200);

        $pendingRequest = $this->createMockPendingRequest($mockResponse);

        $additionalHeaders = [
            'X-Custom-Header' => 'custom-value',
            'X-Request-ID' => 'req-12345',
        ];

        $this->httpMock->shouldReceive('withHeaders')
            ->once()
            ->with(Mockery::on(function ($headers) use ($additionalHeaders) {
                return isset($headers['X-Custom-Header'])
                    && $headers['X-Custom-Header'] === 'custom-value'
                    && isset($headers['X-Request-ID'])
                    && $headers['X-Request-ID'] === 'req-12345'
                    && isset($headers['X-Trust-Key'])
                    && $headers['X-Trust-Key'] === 'test-trust-key-123';
            }))
            ->andReturn($pendingRequest);

        $this->client->makeTrustedGetRequest(
            'test-service',
            '/api/test',
            [],
            $additionalHeaders
        );

        $this->assertTrue(true); // Assert passed if we got here
    }

    public function test_url_building_with_various_formats()
    {
        $mockResponse = $this->createMockResponse(['success' => true], 200);

        // Test endpoint with leading slash
        $pendingRequest1 = $this->createMockPendingRequest($mockResponse);
        $this->httpMock->shouldReceive('withHeaders')
            ->once()
            ->andReturn($pendingRequest1);

        $this->client->makeTrustedGetRequest('test-service', '/api/test');

        // Test endpoint without leading slash
        $pendingRequest2 = $this->createMockPendingRequest($mockResponse);
        $this->httpMock->shouldReceive('withHeaders')
            ->once()
            ->andReturn($pendingRequest2);

        $this->client->makeTrustedGetRequest('test-service', 'api/test');

        // Test with base URL that has trailing slash
        $this->configMock->shouldReceive('get')
            ->with('authservice.service_urls.test-service', Mockery::any())
            ->andReturn('https://test-service.local/');

        $pendingRequest3 = $this->createMockPendingRequest($mockResponse);
        $this->httpMock->shouldReceive('withHeaders')
            ->once()
            ->andReturn($pendingRequest3);

        $this->client->makeTrustedGetRequest('test-service', '/api/test');

        $this->assertTrue(true); // Assert passed if we got here
    }

    public function test_api_key_is_optional()
    {
        $this->markTestSkipped('Skipping test that requires env() mocking without Orchestra Testbench');

        // Remove API key from config
        $this->configMock->shouldReceive('get')
            ->with('authservice.api_keys.test-service', Mockery::any())
            ->andReturn(null);

        $mockResponse = $this->createMockResponse(['success' => true], 200);

        $pendingRequest = $this->createMockPendingRequest($mockResponse);

        $this->httpMock->shouldReceive('withHeaders')
            ->once()
            ->with(Mockery::on(function ($headers) {
                // Should have trust key but not API key
                return isset($headers['X-Trust-Key'])
                    && !isset($headers['X-API-Key']);
            }))
            ->andReturn($pendingRequest);

        // Should work without API key (only trust key is required)
        $response = $this->client->makeTrustedGetRequest(
            'test-service',
            '/api/test'
        );

        $this->assertTrue($response->successful());
    }

    public function test_retry_configuration()
    {
        $mockResponse = $this->createMockResponse(['success' => true], 200);

        $pendingRequest = $this->createMockPendingRequest($mockResponse);

        $this->httpMock->shouldReceive('withHeaders')
            ->once()
            ->andReturn($pendingRequest);

        $this->client
            ->setRetries(5, 200)
            ->makeTrustedGetRequest('test-service', '/api/test');

        // Verify retry settings using reflection
        $reflection = new \ReflectionClass($this->client);

        $retriesProperty = $reflection->getProperty('retries');
        $retriesProperty->setAccessible(true);
        $this->assertEquals(5, $retriesProperty->getValue($this->client));

        $delayProperty = $reflection->getProperty('retryDelay');
        $delayProperty->setAccessible(true);
        $this->assertEquals(200, $delayProperty->getValue($this->client));
    }

    public function test_method_chaining()
    {
        $mockResponse = $this->createMockResponse(['success' => true], 200);

        $pendingRequest = $this->createMockPendingRequest($mockResponse, 'get', false);

        $this->httpMock->shouldReceive('withHeaders')
            ->once()
            ->andReturn($pendingRequest);

        // Test that all setter methods return $this for chaining
        $response = $this->client
            ->setTimeout(45)
            ->setRetries(3, 150)
            ->throwOnError(false)
            ->makeTrustedGetRequest('test-service', '/api/test');

        $this->assertTrue($response->successful());

        // Verify all settings were applied
        $reflection = new \ReflectionClass($this->client);

        $timeoutProperty = $reflection->getProperty('timeout');
        $timeoutProperty->setAccessible(true);
        $this->assertEquals(45, $timeoutProperty->getValue($this->client));

        $retriesProperty = $reflection->getProperty('retries');
        $retriesProperty->setAccessible(true);
        $this->assertEquals(3, $retriesProperty->getValue($this->client));

        $throwProperty = $reflection->getProperty('throwOnError');
        $throwProperty->setAccessible(true);
        $this->assertFalse($throwProperty->getValue($this->client));
    }

    public function test_delete_request_with_body_data()
    {
        $deleteData = [
            'ids' => [1, 2, 3],
            'reason' => 'Cleanup',
        ];

        $mockResponse = $this->createMockResponse([
            'success' => true,
            'deleted_count' => 3,
        ], 200);

        $pendingRequest = $this->createMockPendingRequest($mockResponse, 'delete');

        $this->httpMock->shouldReceive('withHeaders')
            ->once()
            ->andReturn($pendingRequest);

        $response = $this->client->makeTrustedDeleteRequest(
            'test-service',
            '/api/v1/bulk-delete',
            $deleteData
        );

        $this->assertTrue($response->successful());
        $this->assertEquals(3, $response->json('deleted_count'));
    }

    public function test_accept_and_content_type_headers_are_set()
    {
        $mockResponse = $this->createMockResponse(['success' => true], 200);

        $pendingRequest = $this->createMockPendingRequest($mockResponse);

        $this->httpMock->shouldReceive('withHeaders')
            ->once()
            ->with(Mockery::on(function ($headers) {
                return isset($headers['Accept'])
                    && $headers['Accept'] === 'application/json'
                    && isset($headers['Content-Type'])
                    && $headers['Content-Type'] === 'application/json';
            }))
            ->andReturn($pendingRequest);

        $this->client->makeTrustedGetRequest('test-service', '/api/test');

        $this->assertTrue(true); // Assert passed if we got here
    }

    public function test_bearer_token_with_all_http_methods()
    {
        $bearerToken = 'test-bearer-token-xyz';

        // Test GET
        $mockResponse1 = $this->createMockResponse(['success' => true], 200);
        $pendingRequest1 = $this->createMockPendingRequest($mockResponse1);
        $this->httpMock->shouldReceive('withHeaders')
            ->once()
            ->with(Mockery::on(function ($headers) use ($bearerToken) {
                return isset($headers['Authorization'])
                    && $headers['Authorization'] === 'Bearer ' . $bearerToken;
            }))
            ->andReturn($pendingRequest1);

        $this->client->makeTrustedGetRequest('test-service', '/api/test', [], [], $bearerToken);

        // Test POST
        $mockResponse2 = $this->createMockResponse(['success' => true], 200);
        $pendingRequest2 = $this->createMockPendingRequest($mockResponse2, 'post');
        $this->httpMock->shouldReceive('withHeaders')
            ->once()
            ->with(Mockery::on(function ($headers) use ($bearerToken) {
                return isset($headers['Authorization'])
                    && $headers['Authorization'] === 'Bearer ' . $bearerToken;
            }))
            ->andReturn($pendingRequest2);

        $this->client->makeTrustedPostRequest('test-service', '/api/test', [], [], $bearerToken);

        // Test PUT
        $mockResponse3 = $this->createMockResponse(['success' => true], 200);
        $pendingRequest3 = $this->createMockPendingRequest($mockResponse3, 'put');
        $this->httpMock->shouldReceive('withHeaders')
            ->once()
            ->with(Mockery::on(function ($headers) use ($bearerToken) {
                return isset($headers['Authorization'])
                    && $headers['Authorization'] === 'Bearer ' . $bearerToken;
            }))
            ->andReturn($pendingRequest3);

        $this->client->makeTrustedPutRequest('test-service', '/api/test', [], [], $bearerToken);

        // Test PATCH
        $mockResponse4 = $this->createMockResponse(['success' => true], 200);
        $pendingRequest4 = $this->createMockPendingRequest($mockResponse4, 'patch');
        $this->httpMock->shouldReceive('withHeaders')
            ->once()
            ->with(Mockery::on(function ($headers) use ($bearerToken) {
                return isset($headers['Authorization'])
                    && $headers['Authorization'] === 'Bearer ' . $bearerToken;
            }))
            ->andReturn($pendingRequest4);

        $this->client->makeTrustedPatchRequest('test-service', '/api/test', [], [], $bearerToken);

        // Test DELETE
        $mockResponse5 = $this->createMockResponse(['success' => true], 200);
        $pendingRequest5 = $this->createMockPendingRequest($mockResponse5, 'delete');
        $this->httpMock->shouldReceive('withHeaders')
            ->once()
            ->with(Mockery::on(function ($headers) use ($bearerToken) {
                return isset($headers['Authorization'])
                    && $headers['Authorization'] === 'Bearer ' . $bearerToken;
            }))
            ->andReturn($pendingRequest5);

        $this->client->makeTrustedDeleteRequest('test-service', '/api/test', [], [], $bearerToken);

        $this->assertTrue(true); // Assert passed if we got here
    }

    /**
     * Helper method to create a mock Response object
     */
    protected function createMockResponse(array $data, int $status): Response
    {
        $psr7Response = new Psr7Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($data)
        );

        return new Response($psr7Response);
    }

    /**
     * Helper method to create a mock PendingRequest object
     */
    protected function createMockPendingRequest(
        Response $response,
        string $method = 'get',
        bool $shouldThrow = true,
        array $params = []
    ): PendingRequest {
        $pendingRequest = Mockery::mock(PendingRequest::class);

        $pendingRequest->shouldReceive('timeout')
            ->andReturnSelf();

        $pendingRequest->shouldReceive('retry')
            ->andReturnSelf();

        if (!$shouldThrow) {
            $pendingRequest->shouldReceive('withoutThrow')
                ->andReturnSelf();
        }

        switch ($method) {
            case 'get':
                $pendingRequest->shouldReceive('get')
                    ->andReturn($response);
                break;
            case 'post':
                $pendingRequest->shouldReceive('post')
                    ->andReturn($response);
                break;
            case 'put':
                $pendingRequest->shouldReceive('put')
                    ->andReturn($response);
                break;
            case 'patch':
                $pendingRequest->shouldReceive('patch')
                    ->andReturn($response);
                break;
            case 'delete':
                $pendingRequest->shouldReceive('delete')
                    ->andReturn($response);
                break;
        }

        return $pendingRequest;
    }
}
