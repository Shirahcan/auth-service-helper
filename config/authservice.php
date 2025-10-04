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
