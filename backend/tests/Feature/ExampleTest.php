<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_health_endpoint_returns_service_status(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()->assertJson([
            'status' => 'ok',
            'service' => 'adoptplace-api',
        ]);
    }

    public function test_dashboard_api_requires_authentication(): void
    {
        $this->getJson('/api/v1/organization/pets')
            ->assertUnauthorized();
    }
}
