<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthApiTest extends TestCase
{
    public function test_api_health_endpoint_returns_service_status(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'service' => 'master-in-tech-api',
            ])
            ->assertJsonPath('database.status', 'ok')
            ->assertJsonStructure([
                'database' => ['status', 'driver'],
                'redis' => ['available', 'latency_ms', 'cache_store', 'queue_connection'],
            ]);
    }

    public function test_api_health_reports_database_error_without_leaking_details(): void
    {
        $expectedDriver = config('database.default');

        DB::shouldReceive('select')
            ->once()
            ->with('select 1')
            ->andThrow(new \RuntimeException('connection refused'));

        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('database.status', 'error')
            ->assertJsonPath('database.driver', $expectedDriver)
            ->assertJsonMissingPath('message')
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace');

        // Sanity: the underlying failure detail never reaches the client.
        $this->assertStringNotContainsString('connection refused', $response->getContent());
    }

    public function test_up_probe_endpoint_is_available(): void
    {
        $this->get('/up')->assertOk();
    }
}
