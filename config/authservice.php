<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Auth Service Base URL
    |--------------------------------------------------------------------------
    |
    | The base URL of your Authentication Microservice
    |
    */
    'auth_service_base_url' => env('AUTH_SERVICE_BASE_URL', 'http://localhost:8000'),

    /*
    |--------------------------------------------------------------------------
    | Service API Key
    |--------------------------------------------------------------------------
    |
    | Your service's API key for authenticating with the auth service
    |
    */
    'auth_service_api_key' => env('AUTH_SERVICE_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Service Slug
    |--------------------------------------------------------------------------
    |
    | Your service's unique identifier in the auth service
    |
    */
    'service_slug' => env('AUTH_SERVICE_SLUG'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | HTTP request timeout in seconds
    |
    */
    'timeout' => env('AUTH_SERVICE_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Service Trust Keys
    |--------------------------------------------------------------------------
    |
    | Trust keys for authenticating with other trusted services.
    | These keys are used by TrustedServiceClient for service-to-service
    | communication. Keys can also be set via environment variables using
    | the format: {SERVICE_SLUG}_TRUST_KEY (e.g., DOCUMENTS_SERVICE_TRUST_KEY)
    |
    */
    'trust_keys' => [
        // 'documents-service' => env('DOCUMENTS_SERVICE_TRUST_KEY'),
        // 'consultancy-service' => env('CONSULTANCY_SERVICE_TRUST_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Service URLs
    |--------------------------------------------------------------------------
    |
    | Base URLs for trusted services. Used by TrustedServiceClient to build
    | full request URLs. URLs can also be set via environment variables using
    | the format: {SERVICE_SLUG}_SERVICE_URL (e.g., DOCUMENTS_SERVICE_SERVICE_URL)
    |
    */
    'service_urls' => [
        // 'documents-service' => env('DOCUMENTS_SERVICE_SERVICE_URL', 'http://localhost:8001'),
        // 'consultancy-service' => env('CONSULTANCY_SERVICE_SERVICE_URL', 'http://localhost:8002'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Service API Keys
    |--------------------------------------------------------------------------
    |
    | Optional API keys for additional authentication with trusted services.
    | These are injected as X-API-Key headers alongside trust keys.
    | Keys can also be set via environment variables using
    | the format: {SERVICE_SLUG}_API_KEY (e.g., DOCUMENTS_SERVICE_API_KEY)
    |
    */
    'api_keys' => [
        // 'documents-service' => env('DOCUMENTS_SERVICE_API_KEY'),
        // 'consultancy-service' => env('CONSULTANCY_SERVICE_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trust Verification Caching
    |--------------------------------------------------------------------------
    |
    | Enable caching of trust key validation results to reduce API calls.
    | This significantly improves performance for high-frequency
    | service-to-service communication.
    |
    */
    'cache_trust_results' => env('AUTH_SERVICE_CACHE_TRUST_RESULTS', true),

    /*
    |--------------------------------------------------------------------------
    | Trust Cache TTL
    |--------------------------------------------------------------------------
    |
    | Time-to-live for cached trust validation results in seconds.
    | Default is 900 seconds (15 minutes). Lower values provide better
    | security (faster revocation) but more API calls.
    |
    */
    'trust_cache_ttl' => env('AUTH_SERVICE_TRUST_CACHE_TTL', 900),

    /*
    |--------------------------------------------------------------------------
    | Trust Verification Endpoint
    |--------------------------------------------------------------------------
    |
    | The API endpoint path for validating trust keys. This is appended
    | to the auth_service_base_url when making validation requests.
    |
    */
    'trust_verify_endpoint' => env('AUTH_SERVICE_TRUST_VERIFY_ENDPOINT', 'services/validate-trust-key'),

    /*
    |--------------------------------------------------------------------------
    | Login Roles
    |--------------------------------------------------------------------------
    |
    | Optional array of roles required to access the login page
    | Leave empty or null to allow all users
    |
    */
    'login_roles' => env('AUTH_SERVICE_LOGIN_ROLES') ? explode(',', env('AUTH_SERVICE_LOGIN_ROLES')) : null,

    /*
    |--------------------------------------------------------------------------
    | Callback URL
    |--------------------------------------------------------------------------
    |
    | Default callback URL after authentication
    |
    */
    'callback_url' => env('AUTH_SERVICE_CALLBACK_URL', '/auth/callback'),

    /*
    |--------------------------------------------------------------------------
    | Redirect After Login
    |--------------------------------------------------------------------------
    |
    | Where to redirect users after successful login
    |
    */
    'redirect_after_login' => env('AUTH_SERVICE_REDIRECT_AFTER_LOGIN', '/'),

    /*
    |--------------------------------------------------------------------------
    | Auth Guard Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the authservice guard and provider
    |
    */
    'guard' => [
        'driver' => 'authservice',
        'provider' => 'authservice',
    ],

    'provider' => [
        'driver' => 'authservice',
    ],
];
