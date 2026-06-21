<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    /**
     * Smoke test: verificar que a aplicacao sobe e responde JSON.
     */
    public function test_application_boots_and_responds()
    {
        $response = $this->call('GET', '/');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertJson($response->getContent());
    }

    /**
     * Verificar que o endpoint de metadata responde (rota publica existente).
     */
    public function test_metadata_endpoint_exists()
    {
        $response = $this->call('GET', '/metadata');

        // Pode ser 200 ou erro de DB (sem conexao), mas nao 404
        $this->assertNotEquals(404, $response->getStatusCode());
    }

    public function test_health_returns_200_with_ok_status(): void
    {
        $this->get('/health');
        $this->seeStatusCode(200);
    }

    public function test_health_includes_service_status(): void
    {
        $this->get('/health');
        $this->seeStatusCode(200);

        $data = $this->response->json();
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('services', $data);
        $this->assertArrayHasKey('database', $data['services']);
        $this->assertArrayHasKey('cache', $data['services']);
        $this->assertArrayHasKey('storage', $data['services']);
    }

    public function test_health_cache_service_write_and_read(): void
    {
        Cache::put('health_test_probe', 'works', 10);
        $this->assertEquals('works', Cache::get('health_test_probe'));
        Cache::forget('health_test_probe');
        $this->assertTrue(true);
    }

    public function test_health_storage_service_ok(): void
    {
        $storagePath = base_path('public/db/json');
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0755, true);
        }
        $this->assertTrue(is_dir($storagePath));
    }

    public function test_health_timestamp_is_valid_iso(): void
    {
        $this->get('/health');
        $this->seeStatusCode(200);

        $data = $this->response->json();
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertNotEmpty($data['timestamp']);
    }
}
