<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;
use App\Helpers\GenerateStaticJsons;

class GenerateStaticJsonsTest extends TestCase
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
        if (is_dir($this->jsonDir)) {
            foreach (glob($this->jsonDir . '/test_*') as $file) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function createTestJson(string $filename, array $data): string
    {
        $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = md5($content);

        $payload = [
            '_meta' => [
                'hash' => $hash,
                'generated_at' => date('c'),
            ],
            'data' => $data,
        ];

        $jsonContent = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->jsonDir . '/' . $filename, $jsonContent);

        return $hash;
    }

    // ── getHash ──────────────────────────────────────────────

    public function test_get_hash_returns_null_for_nonexistent_file(): void
    {
        $hash = GenerateStaticJsons::getHash('test_nao_existe.json');
        $this->assertNull($hash);
    }

    public function test_get_hash_extracts_hash_from_meta_field(): void
    {
        $hash = $this->createTestJson('test_hash_check.json', ['key' => 'value']);

        $result = GenerateStaticJsons::getHash('test_hash_check.json');

        $this->assertNotNull($result);
        $this->assertEquals($hash, $result);
        $this->assertEquals(32, strlen($result));
    }

    public function test_get_hash_falls_back_to_md5_of_content(): void
    {
        $rawData = ['no_meta' => true];
        $content = json_encode($rawData);
        $expectedHash = md5($content);
        file_put_contents($this->jsonDir . '/test_no_meta.json', $content);

        $result = GenerateStaticJsons::getHash('test_no_meta.json');

        $this->assertNotNull($result, 'getHash deve retornar MD5 fallback para JSON sem _meta');
        $this->assertEquals($expectedHash, $result);
    }

    // ── Estrutura do JSON gerado ─────────────────────────────

    public function test_generated_json_has_meta_hash_and_data(): void
    {
        $this->createTestJson('test_structure.json', ['items' => [1, 2, 3]]);

        $content = file_get_contents($this->jsonDir . '/test_structure.json');
        $decoded = json_decode($content, true);

        $this->assertArrayHasKey('_meta', $decoded);
        $this->assertArrayHasKey('hash', $decoded['_meta']);
        $this->assertArrayHasKey('generated_at', $decoded['_meta']);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertEquals(['items' => [1, 2, 3]], $decoded['data']);
    }

    public function test_hash_is_md5_of_raw_data_not_payload(): void
    {
        $rawData = ['test' => 'hash_consistency'];
        $hash = $this->createTestJson('test_hash_consistency.json', $rawData);

        $expectedHash = md5(json_encode($rawData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assertEquals($expectedHash, $hash);
    }
}
