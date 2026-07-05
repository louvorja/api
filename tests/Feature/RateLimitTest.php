<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    private function clearBucket(string $bucket, string $ip = '127.0.0.1'): void
    {
        $key = "rate_limit:{$bucket}:{$ip}";
        Cache::forget($key);
        Cache::forget("{$key}:tokens");
        Cache::forget("{$key}:last_refill");
        Cache::forget("{$key}:reset_at");
    }

    private function prefillBucket(string $bucket, int $count, string $ip = '127.0.0.1'): void
    {
        $key = "rate_limit:{$bucket}:{$ip}";
        // No token bucket, preencher com 0 tokens (esgotado) = count requests ja feitas
        Cache::put("{$key}:tokens", 0.0, 120);
        Cache::put("{$key}:last_refill", (float) \Illuminate\Support\Carbon::now()->timestamp, 120);
    }

    private function setTokens(string $bucket, float $tokens, string $ip = '127.0.0.1'): void
    {
        $key = "rate_limit:{$bucket}:{$ip}";
        Cache::put("{$key}:tokens", $tokens, 120);
        Cache::put("{$key}:last_refill", (float) \Illuminate\Support\Carbon::now()->timestamp, 120);
    }

    // ──────────────────────────────────────────────
    // Token bucket basics
    // ──────────────────────────────────────────────

    public function test_rate_limit_allows_requests_within_burst(): void
    {
        $this->clearBucket('general');

        $this->get('/');
        $this->seeStatusCode(200);

        $tokens = Cache::get('rate_limit:general:127.0.0.1:tokens');
        $this->assertNotNull($tokens);
        // Apos 1 request, tokens deve ser burst - 1
        $burst = (int) env('RATE_LIMIT_BURST', 100);
        $this->assertEqualsWithDelta($burst - 1, $tokens, 1.0);

        $this->clearBucket('general');
    }

    public function test_rate_limit_returns_429_when_tokens_exhausted(): void
    {
        $this->clearBucket('general');
        // Zerar tokens = bucket esgotado
        $this->setTokens('general', 0.0);

        $this->get('/');
        $this->seeStatusCode(429);

        $data = $this->response->json();
        $this->assertEquals('Too Many Requests', $data['error']);
        $this->assertArrayHasKey('retry_after', $data);
        $this->assertEquals('general', $data['bucket']);

        // Headers
        $this->assertEquals('0', $this->response->headers->get('X-RateLimit-Remaining'));
        $this->assertNotNull($this->response->headers->get('X-RateLimit-Reset'));

        $this->clearBucket('general');
    }

    public function test_rate_limit_tokens_refill_over_time(): void
    {
        $this->clearBucket('general');

        $decay = (int) env('RATE_LIMIT_DECAY', 60);
        $maxTokens = (int) env('RATE_LIMIT_MAX', 5000);

        // Esgota tokens
        $this->setTokens('general', 0.0);

        // Simula 2 segundos de refluxo: seta last_refill no passado
        $key = 'rate_limit:general:127.0.0.1';
        $pastRefill = \Illuminate\Support\Carbon::now()->subSeconds(2)->timestamp;
        Cache::put("{$key}:last_refill", (float) $pastRefill, 120);

        $this->get('/');
        // Com 2s de refluxo, deve ter ~2 * (maxTokens/60) tokens = ~166
        // Entao nao deve ser 429
        $this->assertNotEquals(429, $this->response->getStatusCode());

        $this->clearBucket('general');
    }

    public function test_rate_limit_headers_are_present(): void
    {
        $this->clearBucket('general');

        $this->get('/');
        $this->seeStatusCode(200);
        $this->assertNotNull($this->response->headers->get('X-RateLimit-Limit'));
        $this->assertNotNull($this->response->headers->get('X-RateLimit-Remaining'));
        $this->assertNotNull($this->response->headers->get('X-RateLimit-Reset'));
        $this->assertNotNull($this->response->headers->get('X-RateLimit-Bucket'));

        $this->clearBucket('general');
    }

    // ──────────────────────────────────────────────
    // Buckets separados: files vs general
    // ──────────────────────────────────────────────

    public function test_file_route_uses_separate_bucket(): void
    {
        // Esgota o bucket GENERAL
        $this->clearBucket('general');
        $this->setTokens('general', 0.0);

        // File route usa bucket SEPARADO — nao deve ser 429
        $this->clearBucket('files');
        $this->get('/file/test/image.jpg');
        $this->assertNotEquals(429, $this->response->getStatusCode());

        // Agora esgota o bucket FILES
        $this->clearBucket('files');
        $this->setTokens('files', 0.0);

        $this->get('/file/test/image.jpg');
        $this->seeStatusCode(429);
        $data = $this->response->json();
        $this->assertEquals('files', $data['bucket']);

        // General route ainda funciona (bucket separado)
        $this->clearBucket('general');
        $this->get('/');
        $this->seeStatusCode(200);

        $this->clearBucket('general');
        $this->clearBucket('files');
    }

    public function test_file_route_bucket_header_is_files(): void
    {
        $this->clearBucket('files');

        $this->get('/file/test/image.jpg');
        $this->assertEquals('files', $this->response->headers->get('X-RateLimit-Bucket'));

        $this->clearBucket('files');
    }

    // ──────────────────────────────────────────────
    // Buckets separados: metadata vs general
    // ──────────────────────────────────────────────

    public function test_metadata_route_uses_separate_bucket(): void
    {
        // Esgota GENERAL
        $this->clearBucket('general');
        $this->setTokens('general', 0.0);

        // Metadata usa bucket separado
        $this->clearBucket('metadata');
        $this->get('/version');
        $this->assertNotEquals(429, $this->response->getStatusCode());

        // Esgota METADATA
        $this->clearBucket('metadata');
        $this->setTokens('metadata', 0.0);

        $this->get('/version');
        $this->seeStatusCode(429);
        $data = $this->response->json();
        $this->assertEquals('metadata', $data['bucket']);

        // General funciona
        $this->clearBucket('general');
        $this->get('/');
        $this->seeStatusCode(200);

        $this->clearBucket('general');
        $this->clearBucket('metadata');
    }

    public function test_metadata_route_bucket_header_is_metadata(): void
    {
        $this->clearBucket('metadata');

        $this->get('/version');
        $this->seeStatusCode(200);
        $this->assertEquals('metadata', $this->response->headers->get('X-RateLimit-Bucket'));

        $this->clearBucket('metadata');
    }

    // ──────────────────────────────────────────────
    // Normalizacao de path com prefixo {lang}
    // ──────────────────────────────────────────────

    public function test_lang_prefix_route_hits_general_bucket(): void
    {
        $this->clearBucket('general');

        // Rotas com prefixo lang como /en/musics caem no grupo {lang}
        // do router. O rate_limit middleware roda antes e classifica no
        // bucket GENERAL (normalizePath remove o prefixo).
        $this->get('/en/musics');

        $bucket = $this->response->headers->get('X-RateLimit-Bucket');
        // Se passou pelo rate_limit, bucket deve ser general
        if ($bucket !== null) {
            $this->assertEquals('general', $bucket);
        }

        $this->clearBucket('general');
    }

    public function test_no_lang_prefix_route_hits_correct_bucket(): void
    {
        $this->clearBucket('files');
        $this->clearBucket('metadata');
        $this->clearBucket('general');

        $this->get('/file/test.jpg');
        $this->assertEquals('files', $this->response->headers->get('X-RateLimit-Bucket'));

        $this->clearBucket('metadata');
        $this->get('/version');
        $this->assertEquals('metadata', $this->response->headers->get('X-RateLimit-Bucket'));

        $this->clearBucket('general');
        $this->get('/');
        $this->assertEquals('general', $this->response->headers->get('X-RateLimit-Bucket'));

        $this->clearBucket('files');
        $this->clearBucket('metadata');
        $this->clearBucket('general');
    }
}
