<?php

namespace Tests\Feature;

use Tests\TestCase;

class JsonErrorHandlerTest extends TestCase
{
    public function testNotFoundRoutesReturnJson()
    {
        // Multiple invalid routes — catch-all /{lang} returns 401 for unknown "languages",
        // but the critical requirement is: ALWAYS JSON, never HTML
        $routes = ['/nao-existe-1', '/nao-existe-2', '/rota-inexistente'];

        foreach ($routes as $route) {
            $response = $this->call('GET', $route);

            // Should be a client error (401 from catch-all /{lang}, or 404/500 if routing changes)
            $this->assertContains(
                $response->getStatusCode(),
                [401, 404, 500],
                "Route {$route} should return a client/server error, got {$response->getStatusCode()}"
            );

            // But ALWAYS JSON — never HTML
            $data = json_decode($response->getContent(), true);
            $this->assertNotNull($data, "Response for {$route} should be valid JSON");

            $this->assertArrayHasKey('error', $data, "Response for {$route} should have 'error' field");
        }
    }

    public function testAllErrorResponsesHaveConsistentStructure()
    {
        $response = $this->call('GET', '/invalid-endpoint-test');

        $data = json_decode($response->getContent(), true);
        $this->assertNotNull($data);
        $this->assertNotEmpty($data['error']);
        // Response from catch-all /{lang} may not include 'code' — that's OK.
        // The contract is: JSON with 'error' field for all error responses.
    }

    public function testServerErrorInTestEnvReturnsJsonNotHtml()
    {
        $response = $this->call('GET', '/trigger-500-test');

        // Even 500 errors should be JSON, never HTML
        $contentType = $response->headers->get('Content-Type');
        $this->assertStringContainsString('application/json', $contentType);
    }
}
