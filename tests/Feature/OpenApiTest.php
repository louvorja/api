<?php

namespace Tests\Feature;

use Tests\TestCase;

class OpenApiTest extends TestCase
{
    public function test_openapi_spec_endpoint_returns_json()
    {
        $response = $this->call('GET', '/openapi.json');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));
    }

    public function test_openapi_spec_has_valid_structure()
    {
        $response = $this->call('GET', '/openapi.json');
        $spec = json_decode($response->getContent(), true);

        $this->assertEquals('3.0.0', $spec['openapi']);
        $this->assertArrayHasKey('info', $spec);
        $this->assertEquals('LouvorJA API', $spec['info']['title']);
    }

    public function test_openapi_spec_has_security_scheme()
    {
        $response = $this->call('GET', '/openapi.json');
        $spec = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('components', $spec);
        $this->assertArrayHasKey('securitySchemes', $spec['components']);
        $this->assertArrayHasKey('ApiToken', $spec['components']['securitySchemes']);
    }

    public function test_openapi_spec_documents_json_db_endpoint()
    {
        $response = $this->call('GET', '/openapi.json');
        $spec = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('/json_db/{file}', $spec['paths']);
        $this->assertArrayHasKey('get', $spec['paths']['/json_db/{file}']);
    }

    public function test_swagger_ui_endpoint_returns_html()
    {
        $response = $this->call('GET', '/documentation');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('swagger-ui', $response->getContent());
    }
}
