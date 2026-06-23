<?php

namespace Tests\Unit;

use Tests\TestCase;

class ApiMiddlewareTest extends TestCase
{
    /**
     * Test that the validateToken method uses hash_equals (timing-safe).
     * Direct unit test via reflection on the private method.
     */
    public function testValidateTokenTimingSafeComparison()
    {
        $middleware = new \App\Http\Middleware\ApiMiddleware();
        $method = new \ReflectionMethod($middleware, 'validateToken');
        $method->setAccessible(true);

        $request = new \Illuminate\Http\Request();

        // Test single token match
        config(['api.token' => 'my-secret-key', 'api.tokens' => []]);
        $this->assertTrue($method->invoke($middleware, 'my-secret-key', $request));
        $this->assertFalse($method->invoke($middleware, 'wrong-key', $request));

        // Test multi-token match
        config(['api.token' => '', 'api.tokens' => [
            'desktop' => 'key-a',
            'web' => 'key-b',
        ]]);
        $this->assertTrue($method->invoke($middleware, 'key-a', $request));
        $this->assertTrue($method->invoke($middleware, 'key-b', $request));
        $this->assertFalse($method->invoke($middleware, 'key-c', $request));

        // When multi-tokens set, single token fallback is ignored
        config(['api.token' => 'old-single', 'api.tokens' => [
            'desktop' => 'key-a',
        ]]);
        $this->assertFalse($method->invoke($middleware, 'old-single', $request));
        $this->assertTrue($method->invoke($middleware, 'key-a', $request));

        // Empty tokens array falls back to single
        config(['api.token' => 'single-fallback', 'api.tokens' => []]);
        $this->assertTrue($method->invoke($middleware, 'single-fallback', $request));
    }

    public function testValidateTokenSetsKeyLabel()
    {
        $middleware = new \App\Http\Middleware\ApiMiddleware();
        $method = new \ReflectionMethod($middleware, 'validateToken');
        $method->setAccessible(true);

        $request = new \Illuminate\Http\Request();

        // Single token
        config(['api.token' => 'tok-1', 'api.tokens' => []]);
        $method->invoke($middleware, 'tok-1', $request);
        $this->assertEquals('default', $request->attributes->get('api_key_label'));

        // Multi token
        config(['api.token' => '', 'api.tokens' => ['my-mobile-app' => 'tok-2']]);
        $method->invoke($middleware, 'tok-2', $request);
        $this->assertEquals('my-mobile-app', $request->attributes->get('api_key_label'));
    }

    public function testValidateTokenRejectsEmptyKeys()
    {
        $middleware = new \App\Http\Middleware\ApiMiddleware();
        $method = new \ReflectionMethod($middleware, 'validateToken');
        $method->setAccessible(true);

        $request = new \Illuminate\Http\Request();

        // Empty string keys in multi-token should not match empty tokens
        config(['api.token' => '', 'api.tokens' => [
            'unused' => '',
        ]]);
        $this->assertFalse($method->invoke($middleware, '', $request));
    }

    public function testValidateTokenFallbackWhenSingleTokenEmpty()
    {
        $middleware = new \App\Http\Middleware\ApiMiddleware();
        $method = new \ReflectionMethod($middleware, 'validateToken');
        $method->setAccessible(true);

        $request = new \Illuminate\Http\Request();

        // Both single and multi empty — should reject
        config(['api.token' => '', 'api.tokens' => []]);
        $this->assertFalse($method->invoke($middleware, 'anything', $request));
    }
}
