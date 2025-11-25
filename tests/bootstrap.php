<?php

/**
 * Test bootstrap file
 * This file provides minimal Laravel helper functions needed for testing
 */

// Load vendor autoload
require_once __DIR__ . '/../vendor/autoload.php';

// Mock the config() helper function if it doesn't exist
// (illuminate/support defines config() that uses app() which we don't have)
if (!function_exists('config')) {
    /**
     * Get / set the specified configuration value.
     *
     * @param  array|string|null  $key
     * @param  mixed  $default
     * @return mixed
     */
    function config($key = null, $default = null)
    {
        return Illuminate\Support\Facades\Config::get($key, $default);
    }
}

// Mock the route() helper function if it doesn't exist
if (!function_exists('route')) {
    /**
     * Generate the URL to a named route.
     *
     * @param  string  $name
     * @param  mixed  $parameters
     * @param  bool  $absolute
     * @return string
     */
    function route($name, $parameters = [], $absolute = true)
    {
        return Illuminate\Support\Facades\Route::has($name)
            ? '/' . ltrim($name, '/')
            : $name;
    }
}
