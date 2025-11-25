<?php

require __DIR__ . '/../vendor/autoload.php';

// Define config() helper for testing if it doesn't exist
if (!function_exists('config')) {
    function config($key = null, $default = null)
    {
        // This is a minimal implementation for testing purposes only
        // In actual Laravel applications, this is provided by the framework
        return $default;
    }
}

// Define env() helper for testing if it doesn't exist
if (!function_exists('env')) {
    function env($key, $default = null)
    {
        // This is a minimal implementation for testing purposes only
        return $default;
    }
}
