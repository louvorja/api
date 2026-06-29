<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    public function test_rate_limit_increments_on_each_request(): void
    {
        Cache::forget('rate_limit:127.0.0.1');

        $this->get('/');
        $this->seeStatusCode(200);

        $attempts = Cache::get('rate_limit:127.0.0.1', 0);
        $this->assertEquals(1, $attempts);

        Cache::forget('rate_limit:127.0.0.1');
    }

    public function test_rate_limit_returns_429_when_exceeded(): void
    {
        $maxRequests = (int) env('RATE_LIMIT_MAX', 60);
        $key = 'rate_limit:127.0.0.1';

        // Pre-fill cache to max
        Cache::put($key, $maxRequests, 60);
        Cache::put("{$key}:reset_at", \Illuminate\Support\Carbon::now()->addSeconds(60)->timestamp, 61);

        $this->get('/');
        $this->seeStatusCode(429);

        $data = $this->response->json();
        $this->assertEquals('Too Many Requests', $data['error']);
        $this->assertArrayHasKey('retry_after', $data);

        Cache::forget($key);
        Cache::forget("{$key}:reset_at");
    }

    public function test_rate_limit_clears_after_decay(): void
    {
        $key = 'rate_limit:127.0.0.1';

        Cache::put($key, 100, 1); // 1 segundo de decay
        Cache::put("{$key}:reset_at", \Illuminate\Support\Carbon::now()->addSeconds(1)->timestamp, 2);

        sleep(2);

        $this->get('/');
        $this->seeStatusCode(200);

        Cache::forget($key);
        Cache::forget("{$key}:reset_at");
    }
}
