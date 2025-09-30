<?php

use AuthService\Helper\Models\User;

if (!function_exists('authservice_user')) {
    /**
     * Get the currently authenticated user from the authservice guard
     *
     * @return User|null
     */
    function authservice_user(): ?User
    {
        return auth('authservice')->user();
    }
}

if (!function_exists('authservice_id')) {
    /**
     * Get the ID of the currently authenticated user from the authservice guard
     *
     * @return mixed
     */
    function authservice_id()
    {
        return auth('authservice')->id();
    }
}

if (!function_exists('authservice_check')) {
    /**
     * Determine if the current user is authenticated via the authservice guard
     *
     * @return bool
     */
    function authservice_check(): bool
    {
        return auth('authservice')->check();
    }
}

if (!function_exists('authservice_guest')) {
    /**
     * Determine if the current user is a guest via the authservice guard
     *
     * @return bool
     */
    function authservice_guest(): bool
    {
        return auth('authservice')->guest();
    }
}

if (!function_exists('authservice_guard')) {
    /**
     * Get the authservice guard instance
     *
     * @return \Illuminate\Contracts\Auth\Guard|\Illuminate\Contracts\Auth\StatefulGuard
     */
    function authservice_guard()
    {
        return auth('authservice');
    }
}
