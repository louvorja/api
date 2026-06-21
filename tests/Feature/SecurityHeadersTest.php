<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_response_includes_x_content_type_options()
    {
        $response = $this->call('GET', '/');
        $this->assertEquals('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_response_includes_x_frame_options()
    {
        $response = $this->call('GET', '/');
        $this->assertEquals('DENY', $response->headers->get('X-Frame-Options'));
    }

    public function test_response_includes_referrer_policy()
    {
        $response = $this->call('GET', '/');
        $this->assertEquals('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
    }

    public function test_response_includes_x_robots_tag()
    {
        $response = $this->call('GET', '/');
        $this->assertEquals('noindex', $response->headers->get('X-Robots-Tag'));
    }

    public function test_cors_allows_all_by_default()
    {
        $response = $this->call('GET', '/');
        $this->assertEquals('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_cors_preflight_returns_204()
    {
        $response = $this->call('OPTIONS', '/', [], [], [], [
            'REQUEST_METHOD' => 'OPTIONS',
        ]);

        $this->assertEquals(204, $response->getStatusCode());
    }

    public function test_cors_handles_allowed_methods_header()
    {
        $response = $this->call('OPTIONS', '/', [], [], [], [
            'REQUEST_METHOD' => 'OPTIONS',
        ]);

        $methods = $response->headers->get('Access-Control-Allow-Methods');
        $this->assertStringContainsString('GET', $methods);
        $this->assertStringContainsString('POST', $methods);
        $this->assertStringContainsString('DELETE', $methods);
    }
}
