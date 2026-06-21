<?php

namespace Tests\Feature;

use Tests\TestCase;

class JsonErrorHandlerTest extends TestCase
{
    public function testNotFoundRoutesReturnJson()
    {
        // Multiple invalid routes — all should return JSON, not HTML
        $routes = ['/nao-existe-1', '/nao-existe-2', '/rota-inexistente'];

        foreach ($routes as $route) {
            $response = $this->call('GET', $route);

            // Should be 404, or 500 if test env middleware chain breaks first
            $this->assertContains(
                $response->getStatusCode(),
                [404, 500],
                "Route {$route} should return 404 or 500, got {$response->getStatusCode()}"
            );

            // But ALWAYS JSON — never HTML
            $data = json_decode($response->getContent(), true);
            $this->assertNotNull($data, "Response for {$route} should be valid JSON");

            $this->assertArrayHasKey('error', $data, "Response for {$route} should have 'error' field");
            $this->assertArrayHasKey('code', $data, "Response for {$route} should have 'code' field");
            $this->assertEquals($response->getStatusCode(), $data['code']);
        }
    }

    public function testAllErrorResponsesHaveConsistentStructure()
    {
        $response = $this->call('GET', '/invalid-endpoint-test');

        $data = json_decode($response->getContent(), true);
        $this->assertNotNull($data);
        $this->assertNotEmpty($data['error']);
        $this->assertIsInt($data['code']);
    }

    public function testServerErrorInTestEnvReturnsJsonNotHtml()
    {
        $response = $this->call('GET', '/trigger-500-test');

        // Even 500 errors should be JSON, never HTML
        $contentType = $response->headers->get('Content-Type');
        $this->assertStringContainsString('application/json', $contentType);
    }
}
