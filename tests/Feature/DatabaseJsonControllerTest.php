<?php

namespace Tests\Feature;

use Tests\TestCase;

class DatabaseJsonControllerTest extends TestCase
{
    private $jsonDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jsonDir = base_path('public/db/json');
        if (!is_dir($this->jsonDir)) {
            mkdir($this->jsonDir, 0755, true);
        }
        file_put_contents(
            $this->jsonDir . '/config.json',
            json_encode(['app' => 'louvorja', 'version' => '1.0'])
        );
        file_put_contents(
            $this->jsonDir . '/pt_musics.json',
            json_encode([['id' => 1, 'title' => 'Test Music']])
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->jsonDir)) {
            array_map('unlink', glob($this->jsonDir . '/*'));
            rmdir($this->jsonDir);
        }
        parent::tearDown();
    }

    public function test_get_specific_json_file_returns_content()
    {
        $response = $this->withApiToken('/json_db/config');

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('louvorja', $data['app']);
        $this->assertEquals('1.0', $data['version']);
    }

    public function test_get_json_returns_array_data()
    {
        $response = $this->withApiToken('/json_db/pt_musics');

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertEquals('Test Music', $data[0]['title']);
    }

    public function test_get_nonexistent_json_returns_404()
    {
        $response = $this->withApiToken('/json_db/inexistent_file');

        $this->assertEquals(404, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function test_json_db_requires_no_auth_when_debug()
    {
        // APP_DEBUG=true no phpunit.xml — middleware de Api-Token é ignorado
        $response = $this->call('GET', '/json_db/config');

        $this->assertEquals(200, $response->getStatusCode());
    }
}
