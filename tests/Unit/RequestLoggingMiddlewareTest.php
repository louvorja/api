<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Http\Middleware\RequestLoggingMiddleware;
use Illuminate\Http\Request;

class RequestLoggingMiddlewareTest extends TestCase
{
    public function testMiddlewareExtractsLogLevelForSuccessfulRequest()
    {
        $middleware = new RequestLoggingMiddleware();
        $reflection = new \ReflectionMethod($middleware, 'logLevel');

        $reflection->setAccessible(true);

        // 200 with fast response = info
        $this->assertEquals('info', $reflection->invoke($middleware, 200, 100));

        // 201 with fast response = info
        $this->assertEquals('info', $reflection->invoke($middleware, 201, 50));
    }

    public function testMiddlewareExtractsLogLevelForClientErrors()
    {
        $middleware = new RequestLoggingMiddleware();
        $reflection = new \ReflectionMethod($middleware, 'logLevel');
        $reflection->setAccessible(true);

        // 400 = warning
        $this->assertEquals('warning', $reflection->invoke($middleware, 400, 100));

        // 401 = warning
        $this->assertEquals('warning', $reflection->invoke($middleware, 401, 100));

        // 404 = warning
        $this->assertEquals('warning', $reflection->invoke($middleware, 404, 100));

        // 422 = warning
        $this->assertEquals('warning', $reflection->invoke($middleware, 422, 100));

        // 429 = warning
        $this->assertEquals('warning', $reflection->invoke($middleware, 429, 100));
    }

    public function testMiddlewareExtractsLogLevelForServerErrors()
    {
        $middleware = new RequestLoggingMiddleware();
        $reflection = new \ReflectionMethod($middleware, 'logLevel');
        $reflection->setAccessible(true);

        // 500 = error
        $this->assertEquals('error', $reflection->invoke($middleware, 500, 100));

        // 503 = error
        $this->assertEquals('error', $reflection->invoke($middleware, 503, 100));
    }

    public function testMiddlewareFlagsSlowSuccessfulRequestsAsWarning()
    {
        $middleware = new RequestLoggingMiddleware();
        $reflection = new \ReflectionMethod($middleware, 'logLevel');
        $reflection->setAccessible(true);

        // 200 but took 3 seconds = warning
        $this->assertEquals('warning', $reflection->invoke($middleware, 200, 3000));

        // 200 but took exactly 2.1 seconds = warning
        $this->assertEquals('warning', $reflection->invoke($middleware, 200, 2100));

        // 200 and took 2.0 seconds = info (threshold exclusive)
        $this->assertEquals('info', $reflection->invoke($middleware, 200, 2000));
    }

    public function testMiddlewareHasHandleMethod()
    {
        $middleware = new RequestLoggingMiddleware();
        $this->assertTrue(method_exists($middleware, 'handle'));
    }
}
