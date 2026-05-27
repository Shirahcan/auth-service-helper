<?php

namespace Tests\Unit\Sharing;

use AuthService\Helper\AuthServiceHelperServiceProvider;
use AuthService\Helper\Sharing\SharingServiceProvider;
use Orchestra\Testbench\TestCase;

class ServiceProviderRegistrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AuthServiceHelperServiceProvider::class];
    }

    public function test_sharing_provider_is_registered(): void
    {
        $providers = array_keys($this->app->getLoadedProviders());
        $this->assertContains(SharingServiceProvider::class, $providers);
    }
}
