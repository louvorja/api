<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StaticJsonTableETagTest extends TestCase
{
    private string $jsonDir;
    private array $createdFiles = [];

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
        foreach ($this->createdFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        $this->createdFiles = [];
        parent::tearDown();
    }

    private function createJsonWithMeta(string $table, array $data): string
    {
        $raw = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = md5($raw);

        $payload = [
            '_meta' => ['hash' => $hash, 'generated_at' => date('c')],
            'data' => $data,
        ];

        $path = $this->jsonDir . '/' . $table . '.json';
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->createdFiles[] = $path;

        return $hash;
    }

    private function createJsonWithoutMeta(string $table, array $data): string
    {
        $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $path = $this->jsonDir . '/' . $table . '.json';
        file_put_contents($path, $content);
        $this->createdFiles[] = $path;
        return md5($content);
    }

    // ── ETag header ─────────────────────────────────────────────

    public function test_table_response_includes_etag_header(): void
    {
        $this->createJsonWithMeta('etag_musics', [
            ['id' => 1, 'title' => 'Song A'],
            ['id' => 2, 'title' => 'Song B'],
        ]);

        $this->get('/db/etag_musics');
        $this->seeStatusCode(200);

        $etag = $this->response->headers->get('ETag');
        $this->assertNotNull($etag, 'ETag header deve estar presente');
    }

    public function test_table_etag_matches_hash_from_meta(): void
    {
        $hash = $this->createJsonWithMeta('etag_albums', [
            ['id' => 10, 'name' => 'Album X'],
        ]);

        $this->get('/db/etag_albums');
        $this->seeStatusCode(200);

        $etag = $this->response->headers->get('ETag');
        $this->assertNotNull($etag);
        $this->assertEquals($hash, $etag);
    }

    public function test_table_etag_value_is_32_char_md5(): void
    {
        $this->createJsonWithMeta('etag_categories', [
            ['id' => 1, 'name' => 'Cat'],
        ]);

        $this->get('/db/etag_categories');
        $this->seeStatusCode(200);

        $etag = $this->response->headers->get('ETag');
        $this->assertNotNull($etag);
        $this->assertEquals(32, strlen($etag), 'ETag value deve ser MD5 de 32 chars');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $etag);
    }

    // ── If-None-Match → 304 ───────────────────────────────────

    public function test_table_returns_304_when_etag_matches(): void
    {
        $hash = $this->createJsonWithMeta('etag_hymnal', [
            ['id' => 1, 'title' => 'Hino 100'],
        ]);

        $this->call('GET', '/db/etag_hymnal', [], [], [], [
            'HTTP_If-None-Match' => $hash,
        ]);

        $this->seeStatusCode(304);
    }

    public function test_table_returns_200_when_etag_mismatch(): void
    {
        $this->createJsonWithMeta('etag_collection', [
            ['id' => 1, 'name' => 'Col 1'],
        ]);

        $this->call('GET', '/db/etag_collection', [], [], [], [
            'HTTP_If-None-Match' => 'wrong-hash-00000000000000',
        ]);

        $this->seeStatusCode(200);
    }

    public function test_304_response_has_empty_body(): void
    {
        $hash = $this->createJsonWithMeta('etag_empty', [
            ['id' => 1, 'data' => 'test'],
        ]);

        $response = $this->call('GET', '/db/etag_empty', [], [], [], [
            'HTTP_If-None-Match' => $hash,
        ]);

        $this->seeStatusCode(304);
        $this->assertEmpty($response->getContent());
    }

    // ── _meta.data extração automática ─────────────────────────

    public function test_table_extracts_data_from_meta_wrapper(): void
    {
        $this->createJsonWithMeta('etag_songs', [
            ['id' => 1, 'title' => 'Song 1'],
            ['id' => 2, 'title' => 'Song 2'],
            ['id' => 3, 'title' => 'Song 3'],
        ]);

        $this->get('/db/etag_songs');
        $this->seeStatusCode(200);

        $data = $this->response->json();
        $this->assertArrayHasKey('data', $data);

        $this->assertCount(3, $data['data']);
        $this->assertEquals('Song 1', $data['data'][0]['title']);
    }

    public function test_table_returns_raw_data_when_no_meta_wrapper(): void
    {
        $this->createJsonWithoutMeta('etag_legacy', [['id' => 1, 'title' => 'Legacy']]);

        $this->get('/db/etag_legacy');
        $this->seeStatusCode(200);

        $data = $this->response->json();
        $this->assertArrayHasKey('data', $data);
        $this->assertCount(1, $data['data']);
    }
}
