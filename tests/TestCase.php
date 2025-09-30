<?php

namespace Tests;

use AuthService\Helper\AuthServiceHelperServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            AuthServiceHelperServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Setup default configuration
        $app['config']->set('authservice.auth_service_base_url', 'http://localhost:8000');
        $app['config']->set('authservice.auth_service_api_key', 'test_api_key');
        $app['config']->set('authservice.service_slug', 'test-service');
        $app['config']->set('authservice.timeout', 30);
        $app['config']->set('app.name', 'Test App');
    }
}
