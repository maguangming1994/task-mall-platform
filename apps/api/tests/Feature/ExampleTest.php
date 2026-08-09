<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_api_health_endpoint_returns_the_service_status(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertJsonPath('code', 'OK')
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.service', 'task-mall-api')
            ->assertHeader('X-Request-Id');
    }
}
