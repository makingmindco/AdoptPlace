<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_liveness_does_not_require_cache_session_or_database(): void
    {
        $this->withoutExceptionHandling();
        config(['cache.default' => 'unavailable_health_cache', 'session.driver' => 'mongodb',
            'sanctum.stateful' => ['localhost:5173']]);
        $response = $this->withHeader('Origin', 'http://localhost:5173')
            ->getJson('/api/health')->assertOk()->assertJsonPath('status', 'ok');
        $this->assertSame([], $response->headers->getCookies());
    }
}
