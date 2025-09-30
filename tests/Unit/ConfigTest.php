<?php

namespace Tests\Unit;

use Tests\TestCase;

class ConfigTest extends TestCase
{
    /** @test */
    public function it_loads_configuration()
    {
        $this->assertEquals('http://localhost:8000', config('authservice.auth_service_base_url'));
        $this->assertEquals('test_api_key', config('authservice.auth_service_api_key'));
        $this->assertEquals('test-service', config('authservice.service_slug'));
        $this->assertEquals(30, config('authservice.timeout'));
    }

    /** @test */
    public function it_has_default_values()
    {
        $this->assertNull(config('authservice.login_roles'));
        $this->assertEquals('/auth/callback', config('authservice.callback_url'));
        $this->assertEquals('/dashboard', config('authservice.redirect_after_login'));
    }
}
