<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_serves_the_dashboard_without_any_login()
    {
        $response = $this->get('/');

        $response->assertOk();
    }

    public function test_dashboard_is_accessible_without_authentication()
    {
        $response = $this->get('/dashboard');

        $response->assertOk();
    }
}
