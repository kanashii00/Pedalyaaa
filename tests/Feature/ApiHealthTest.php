<?php

namespace Tests\Feature;

use Tests\TestCase;

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
}
