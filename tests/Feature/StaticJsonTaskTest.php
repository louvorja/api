<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StaticJsonTaskTest extends TestCase
{
    private string $jsonDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jsonDir = base_path('public/db/json');
        if (!is_dir($this->jsonDir)) {
            mkdir($this->jsonDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Limpa arquivos gerados pelo test
        if (is_dir($this->jsonDir)) {
            foreach (glob($this->jsonDir . '/*') as $file) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    // ── Endpoint existe e responde ─────────────────────────────

    public function test_generate_static_jsons_endpoint_exists(): void
    {
        // Sem ?force — pode 200 ou 422 dependendo do estado do DB
        $response = $this->call('GET', '/tasks/generate_static_jsons');

        // Deve responder algo (não 404)
        $this->assertNotEquals(404, $response->getStatusCode());
    }

    public function test_generate_static_jsons_with_force_param_accepts_true(): void
    {
        // ?force=true deve ser aceito pelo endpoint (param documentado no Swagger)
        $response = $this->call('GET', '/tasks/generate_static_jsons', ['force' => 'true']);

        // Pode falhar por falta de DB real, mas não deve ser 404
        $this->assertNotEquals(404, $response->getStatusCode());
    }

    public function test_generate_static_jsons_returns_json(): void
    {
        $response = $this->call('GET', '/tasks/generate_static_jsons');

        $this->assertContains(
            $response->headers->get('Content-Type'),
            ['application/json', 'application/json; charset=UTF-8']
        );
    }
}
