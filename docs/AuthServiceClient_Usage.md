# AuthServiceClient Usage Guide

The `AuthServiceClient` class provides HTTP verb utility methods for making API requests to the Authentication Microservice.

## HTTP Verb Methods

The client provides these public methods for making HTTP requests:

### `get(string $endpoint, array $options = []): array`
Send a GET request to the auth service.

**Parameters:**
- `$endpoint` - API endpoint (e.g., `'users'`, `'services/list'`)
- `$options` - Optional configuration array

**Example:**
```php
use AuthService\Helper\Services\AuthServiceClient;

$client = app(AuthServiceClient::class);

// Simple GET request
$services = $client->get('services');

// GET with query parameters
$users = $client->get('users', [
    'query' => [
        'role' => 'admin',
        'active' => true
    ]
]);

// GET with auth token
$profile = $client->get('auth/me', [
    'auth_token' => $token
]);
```

---

### `post(string $endpoint, array $data = [], array $options = []): array`
Send a POST request to the auth service.

**Parameters:**
- `$endpoint` - API endpoint
- `$data` - Request body data (will be sent as JSON)
- `$options` - Optional configuration array

**Example:**
```php
// Create a new service
$result = $client->post('services/register', [
    'name' => 'My Service',
    'slug' => 'my-service',
    'description' => 'Service description'
]);

// POST with auth token
$result = $client->post('auth/logout', [], [
    'auth_token' => $token
]);

// POST with custom headers
$result = $client->post('landing/generate', [
    'action' => 'login',
    'callback_url' => 'https://example.com/callback'
], [
    'headers' => ['X-Custom-Header' => 'value']
]);
```

---

### `put(string $endpoint, array $data = [], array $options = []): array`
Send a PUT request to the auth service.

**Parameters:**
- `$endpoint` - API endpoint
- `$data` - Request body data (will be sent as JSON)
- `$options` - Optional configuration array

**Example:**
```php
// Update a user
$updated = $client->put('users/123', [
    'name' => 'New Name',
    'email' => 'newemail@example.com'
], [
    'auth_token' => $token
]);

// Update with query parameters
$updated = $client->put('services/my-service', [
    'name' => 'Updated Service Name'
], [
    'query' => ['validate' => true]
]);
```

---

### `patch(string $endpoint, array $data = [], array $options = []): array`
Send a PATCH request to the auth service.

**Parameters:**
- `$endpoint` - API endpoint
- `$data` - Request body data (partial update)
- `$options` - Optional configuration array

**Example:**
```php
// Partial update of a user
$updated = $client->patch('users/123', [
    'email' => 'newemail@example.com'
], [
    'auth_token' => $token
]);
```

---

### `delete(string $endpoint, array $options = []): array`
Send a DELETE request to the auth service.

**Parameters:**
- `$endpoint` - API endpoint
- `$options` - Optional configuration array

**Example:**
```php
// Delete a resource
$result = $client->delete('sessions/abc123', [
    'auth_token' => $token
]);

// Delete with custom headers
$result = $client->delete('users/123', [
    'headers' => ['X-Force-Delete' => 'true'],
    'auth_token' => $token
]);
```

---

## Options Array

All HTTP methods accept an `$options` array with these available keys:

| Key | Type | Description |
|-----|------|-------------|
| `headers` | array | Additional HTTP headers to send |
| `query` | array | Query parameters for the URL |
| `json` | array | JSON body (alternative to `$data` param) |
| `auth_token` | string | Bearer token for Authorization header |
| `throw` | bool | Whether to throw exceptions on error (default: `true`) |
| `log_context` | array | Additional context for error logging |

### Examples:

```php
// All options combined
$result = $client->post('endpoint', ['key' => 'value'], [
    'headers' => [
        'X-Custom-Header' => 'value'
    ],
    'query' => [
        'filter' => 'active'
    ],
    'auth_token' => $token,
    'throw' => false,  // Don't throw exceptions
    'log_context' => [
        'user_id' => 123,
        'operation' => 'custom_operation'
    ]
]);

// Query parameters
$users = $client->get('users', [
    'query' => [
        'page' => 1,
        'limit' => 50,
        'sort' => 'created_at'
    ]
]);

// Custom headers
$result = $client->post('endpoint', $data, [
    'headers' => [
        'X-Request-ID' => uniqid(),
        'X-Source' => 'admin-panel'
    ]
]);

// Non-throwing request
$result = $client->delete('resource/123', [
    'throw' => false  // Returns error array instead of throwing
]);
// If error occurs, returns: ['success' => false, 'message' => '...', 'status_code' => 404]
```

---

## Error Handling

### Default Behavior (Throws Exceptions)
By default, all methods throw `GuzzleHttp\Exception\RequestException` on HTTP errors:

```php
use GuzzleHttp\Exception\RequestException;

try {
    $result = $client->post('endpoint', $data);
} catch (RequestException $e) {
    // Handle the error
    $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : null;
    $errorBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : null;
}
```

### Non-Throwing Behavior
Set `'throw' => false` to return error arrays instead:

```php
$result = $client->post('endpoint', $data, [
    'throw' => false
]);

if (!$result['success']) {
    // Handle error
    echo "Error: " . $result['message'];
    echo "Status: " . $result['status_code'];
}
```

---

## Logging

All requests are automatically logged on error with context:

```php
// Automatic logging includes:
// - HTTP method
// - Endpoint
// - Error message
// - Response body

// Add custom context for better debugging
$client->post('endpoint', $data, [
    'log_context' => [
        'user_id' => auth()->id(),
        'operation' => 'create_service',
        'ip_address' => request()->ip()
    ]
]);
```

Log entries will appear in your Laravel logs as:
```
Auth Service request failed {
    "method": "POST",
    "endpoint": "endpoint",
    "error": "...",
    "response": "...",
    "user_id": 123,
    "operation": "create_service",
    "ip_address": "192.168.1.1"
}
```

---

## Complete Examples

### Example 1: User Registration Flow
```php
// Generate landing page for registration
$landing = $client->post('landing/generate', [
    'action' => 'register',
    'callback_url' => route('auth.callback'),
    'metadata' => [
        'source' => 'admin-invite'
    ]
]);

// Check landing status
$status = $client->get("landing/{$landing['session_id']}/status");

// Get user info after registration
if ($status['is_used']) {
    $user = $client->get('auth/me', [
        'auth_token' => $status['result']['token']
    ]);
}
```

### Example 2: Service Management
```php
// List all services
$services = $client->get('services');

// Create a new service
$newService = $client->post('services', [
    'name' => 'Analytics Service',
    'slug' => 'analytics-service'
]);

// Update service
$updated = $client->put('services/analytics-service', [
    'name' => 'Analytics & Reporting Service'
]);

// Check service trust
$trustCheck = $client->post('services/check-trust', [
    'calling_service_key' => config('authservice.auth_service_api_key'),
    'target_service_slug' => 'another-service'
]);
```

### Example 3: Error Handling Strategies
```php
// Strategy 1: Try-catch with exceptions
try {
    $result = $client->post('endpoint', $data);
    // Success
} catch (RequestException $e) {
    if ($e->getResponse() && $e->getResponse()->getStatusCode() === 404) {
        // Handle not found
    } else {
        // Handle other errors
    }
}

// Strategy 2: Non-throwing with error checking
$result = $client->post('endpoint', $data, ['throw' => false]);
if (!$result['success']) {
    match ($result['status_code']) {
        404 => handleNotFound(),
        401 => handleUnauthorized(),
        default => handleGenericError($result['message'])
    };
}
```

---

## Migration from Old Code

### Before (Manual Guzzle calls):
```php
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
```

### After (Using utility methods):
```php
return $this->get('auth/me', [
    'auth_token' => $token,
    'log_context' => ['operation' => 'get_user_profile']
]);
```

**Benefits:**
- ✅ 80% less code
- ✅ Consistent error handling
- ✅ Automatic JSON decoding
- ✅ Better logging with context
- ✅ Easier to read and maintain
