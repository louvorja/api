<?php

namespace Tests\Feature;

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
}
