<?php

namespace Tests\Feature;

use Tests\TestCase;

class RoutesTest extends TestCase
{
    /** @test */
    public function it_registers_auth_login_route()
    {
        $this->assertTrue(route('auth.login') !== null);
    }

    /** @test */
    public function it_registers_auth_generate_route()
    {
        $this->assertTrue(route('auth.generate') !== null);
    }

    /** @test */
    public function it_registers_auth_callback_route()
    {
        $this->assertTrue(route('auth.callback') !== null);
    }

    /** @test */
    public function it_registers_auth_logout_route()
    {
        $this->assertTrue(route('auth.logout') !== null);
    }
}
