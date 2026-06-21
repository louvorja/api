<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DatabaseJsonControllerExportTest extends TestCase
{
    private string $jsonDir;
    private string $testFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jsonDir = base_path('public/db/json');
        $this->testFile = $this->jsonDir . '/musics.json';

        if (!File::isDirectory($this->jsonDir)) {
            File::makeDirectory($this->jsonDir, 0755, true);
        }

        File::put($this->testFile, json_encode([
            ['id' => 1, 'title' => 'Hino 1', 'category' => 'louvor', 'author' => 'Autor A'],
            ['id' => 2, 'title' => 'Hino 2', 'category' => 'adoracao', 'author' => 'Autor B'],
            ['id' => 3, 'title' => 'Hino 3', 'category' => 'louvor', 'author' => 'Autor A'],
            ['id' => 4, 'title' => 'Hino 4', 'category' => '', 'author' => ''],
            ['id' => 5, 'title' => 'Hino 5', 'category' => null, 'author' => null],
        ], JSON_PRETTY_PRINT));
    }

    protected function tearDown(): void
    {
        if (File::exists($this->testFile)) {
            File::delete($this->testFile);
        }

        parent::tearDown();
    }

    // ── Manifest ─────────────────────────────────────────────

    public function test_manifest_returns_structure(): void
    {
        $this->get('/db/manifest');
        $this->seeStatusCode(200);

        $data = $this->response->json();
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);

        $entry = $data[0];
        $this->assertArrayHasKey('file', $entry);
        $this->assertArrayHasKey('table', $entry);
        $this->assertArrayHasKey('path', $entry);

        $musicEntry = collect($data)->first(fn ($e) => $e['table'] === 'musics');
        $this->assertNotNull($musicEntry, 'musics deve estar no manifest');
        $this->assertSame('musics.json', $musicEntry['file']);
        $this->assertSame('/db/musics', $musicEntry['path']);
    }

    public function test_manifest_caches_response(): void
    {
        Cache::forget('db.manifest');

        $this->get('/db/manifest');
        $this->assertTrue(Cache::has('db.manifest'));

        Cache::forget('db.manifest');
    }

    // ── Table (Paginated) ──────────────────────────────────────

    public function test_table_returns_paginated_data(): void
    {
        $this->get('/db/musics');
        $this->seeStatusCode(200);

        $data = $this->response->json();

        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('meta', $data);
        $this->assertIsArray($data['data']);
        $this->assertCount(5, $data['data']);

        $this->assertEquals(5, $data['meta']['total']);
        $this->assertEquals(50, $data['meta']['per_page']);
        $this->assertEquals(1, $data['meta']['current_page']);
        $this->assertEquals(1, $data['meta']['last_page']);
    }

    public function test_table_respects_page_parameter(): void
    {
        $this->get('/db/musics?page=1&per_page=2');
        $this->seeStatusCode(200);

        $data = $this->response->json();

        $this->assertCount(2, $data['data']);
        $this->assertEquals(5, $data['meta']['total']);
        $this->assertEquals(2, $data['meta']['per_page']);
        $this->assertEquals(1, $data['meta']['current_page']);
        $this->assertEquals(3, $data['meta']['last_page']);
    }

    public function test_table_caches_response(): void
    {
        Cache::flush();

        $this->get('/db/musics?page=1&per_page=50');
        $this->assertTrue(Cache::has('db.table.musics.page.1.per_page.50'));

        Cache::flush();
    }

    // ── Categories ─────────────────────────────────────────────

    public function test_categories_returns_unique_values(): void
    {
        $this->get('/db/musics/categories?column=category');
        $this->seeStatusCode(200);

        $categories = $this->response->json();
        $this->assertIsArray($categories);
        $this->assertContains('louvor', $categories);
        $this->assertContains('adoracao', $categories);
        $this->assertNotContains('', $categories);
        $this->assertNotContains(null, $categories);
    }

    public function test_categories_filters_empty_values(): void
    {
        $this->get('/db/musics/categories?column=author');
        $this->seeStatusCode(200);

        $authors = $this->response->json();
        $this->assertIsArray($authors);
        $this->assertContains('Autor A', $authors);
        $this->assertContains('Autor B', $authors);
        $this->assertNotContains('', $authors);
        $this->assertNotContains(null, $authors);
    }

    public function test_categories_requires_column_parameter(): void
    {
        $this->get('/db/musics/categories');
        $this->seeStatusCode(400);
        $this->assertEquals('Parâmetro column é obrigatório', $this->response->json()['error']);
    }

    public function test_categories_returns_404_for_missing_file(): void
    {
        $this->get('/db/nonexistent/categories?column=title');
        $this->seeStatusCode(404);
        $this->assertEquals('Table not found', $this->response->json()['error']);
    }
}
