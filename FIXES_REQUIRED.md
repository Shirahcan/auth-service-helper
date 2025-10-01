# Auth Service Helper - Fixes Required

Based on comprehensive testing on 2025-09-30, the following issues need to be fixed:

## 🔴 Critical - Must Fix First

### 1. API Key Header Not Being Sent in Requests

**File:** [src/Services/AuthServiceClient.php](src/Services/AuthServiceClient.php)
**Lines:** 48-78
**Priority:** CRITICAL
**Impact:** 80% of tests failing (20 out of 25)

**Problem:**
The `X-Service-Key` header configured during Guzzle client instantiation is not being included in actual HTTP requests. When custom headers are provided, the code attempts to merge them with default headers using `$this->client->getConfig('headers')`, but this may return null or an empty array.

**Current Code:**
```php
// Lines 54-60 in AuthServiceClient.php
if (isset($options['headers'])) {
    $requestOptions['headers'] = array_merge(
        $this->client->getConfig('headers') ?? [],
        $options['headers']
    );
}
```

**Proposed Fix:**
```php
// Always start with default headers
$defaultHeaders = [
    'Accept' => 'application/json',
    'Content-Type' => 'application/json',
    'X-Service-Key' => $this->apiKey,
];

$requestOptions['headers'] = $defaultHeaders;

// Merge custom headers if provided
if (isset($options['headers'])) {
    $requestOptions['headers'] = array_merge(
        $requestOptions['headers'],
        $options['headers']
    );
}
```

**Alternative Fix (Store default headers as class property):**
```php
// In constructor
protected array $defaultHeaders;

public function __construct()
{
    $this->baseUrl = config('authservice.auth_service_base_url');
    $this->apiKey = config('authservice.auth_service_api_key');

    $this->defaultHeaders = [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
        'X-Service-Key' => $this->apiKey,
    ];

    $this->client = new Client([
        'base_uri' => $this->baseUrl,
        'timeout' => config('authservice.timeout', 30),
    ]);
}

// In request() method
protected function request(string $method, string $endpoint, array $options = []): array
{
    // ... existing code ...

    $requestOptions['headers'] = $this->defaultHeaders;

    if (isset($options['headers'])) {
        $requestOptions['headers'] = array_merge(
            $requestOptions['headers'],
            $options['headers']
        );
    }

    // ... rest of code ...
}
```

**Testing:**
After fix, re-run: `php test-comprehensive.php`

---

## 🟡 Medium Priority - Fix After Critical

### 2. Add Request/Response Logging for Debugging

**File:** [src/Services/AuthServiceClient.php](src/Services/AuthServiceClient.php)
**Lines:** 48-111
**Priority:** MEDIUM
**Impact:** Improves debuggability

**Problem:**
Currently, there's no way to see what headers are actually being sent in requests, making debugging difficult.

**Proposed Addition:**
```php
protected function request(string $method, string $endpoint, array $options = []): array
{
    try {
        // ... build request options ...

        // Log request details in development/debug mode
        if (config('app.debug', false)) {
            Log::debug('Auth Service Request', [
                'method' => $method,
                'endpoint' => $endpoint,
                'headers' => $requestOptions['headers'] ?? [],
                'has_body' => isset($requestOptions['json']),
            ]);
        }

        $response = $this->client->request($method, $this->buildApiUrl($endpoint), $requestOptions);

        // ... rest of code ...
    }
}
```

---

## 🟢 Low Priority - Nice to Have

### 3. Improve Error Messages

**File:** [src/Services/AuthServiceClient.php](src/Services/AuthServiceClient.php)
**Lines:** 86-110
**Priority:** LOW
**Impact:** Better developer experience

**Problem:**
Error messages don't clearly indicate when API key is missing or invalid.

**Proposed Enhancement:**
```php
catch (RequestException $e) {
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

    // Add specific error message for 401
    if ($statusCode === 401) {
        $logContext['hint'] = 'Check that AUTH_SERVICE_API_KEY is set correctly';
    }

    Log::error('Auth Service request failed', $logContext);

    // ... rest of code ...
}
```

---

## Testing Checklist

After implementing fixes, verify:

- [ ] Fix 1: API Key Header Transmission
  - [ ] All 20 previously failing tests now pass
  - [ ] User::all() returns actual users
  - [ ] UserQueryBuilder methods work correctly
  - [ ] AuthServiceClient methods return data

- [ ] Comprehensive Test Suite
  - [ ] Re-run: `php test-comprehensive.php`
  - [ ] Pass rate should be > 95%
  - [ ] Test User instance methods with real data
  - [ ] Test UserCollection methods with real data
  - [ ] Test CRUD operations (create, update, delete)

- [ ] Integration Testing
  - [ ] Test in actual Laravel application
  - [ ] Verify auth flows work end-to-end
  - [ ] Test middleware (HasRoleMiddleware, TrustedServiceMiddleware)
  - [ ] Test Blade components

---

## Implementation Order

1. **Fix #1** - API Key Header (CRITICAL)
2. **Run Tests** - Verify all tests pass
3. **Fix #2** - Add Logging (MEDIUM)
4. **Fix #3** - Improve Error Messages (LOW)
5. **Final Testing** - Full integration test

---

## Notes

- The test script (`test-comprehensive.php`) is ready to use for validation
- All tests currently return 401 due to missing API key header
- Once Fix #1 is applied, we can identify any additional issues
- The package structure and logic appear sound; this is primarily a configuration/header issue

---

**Status:** Ready for implementation
**Next Action:** Apply Fix #1 to [AuthServiceClient.php](src/Services/AuthServiceClient.php)
