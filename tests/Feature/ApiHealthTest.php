<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * The on-board ESP32 probes /api/health (no API key) to verify the bearer
 * before sending any rental, GPS or accident traffic. These tests confirm the
 * route exists and answers 200; they do not touch the database or any tunnel.
 */
class ApiHealthTest extends TestCase
{
    public function test_health_route_is_public_and_returns_200(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'healthy');
    }

    public function test_health_route_requires_no_api_key(): void
    {
        $this->get('/api/health')->assertOk();
    }

    public function test_health_route_reports_database_unavailability(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->with('select 1')
            ->andThrow(new \RuntimeException('database unavailable'));

        $this->getJson('/api/health')
            ->assertServiceUnavailable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.status', 'unhealthy');
    }
}
