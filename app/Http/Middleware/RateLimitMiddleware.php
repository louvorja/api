<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class RateLimitMiddleware
{
    /**
     * Rotas que servem conteudo estatico (imagens, musicas, arquivos).
     * O app desktop faz batch downloads de capas, slides e musicas.
     */
    private const FILE_ROUTES = [
        '/file/',
        '/player',
    ];

    /**
     * Rotas de metadados leves.
     */
    private const METADATA_ROUTES = [
        '/version',
        '/version_log',
        '/metadata',
    ];

    private const BUCKET_FILE = 'files';
    private const BUCKET_METADATA = 'metadata';
    private const BUCKET_GENERAL = 'general';

    /**
     * Prefixos de idioma que aparecem nas rotas (ex: /pt-BR/file/...).
     * Removidos para classificacao correta de bucket.
     */
    private const LANG_PREFIXES = [
        'pt-BR', 'en', 'es', 'fr', 'de', 'it', 'ru', 'zh', 'ja', 'ko',
    ];

    /**
     * Rate limiting com Token Bucket por tipo de rota.
     *
     * Cada bucket (arquivos, metadados, geral) tem seu proprio token bucket
     * no cache. O token bucket permite bursts (picos) instantaneos e depois
     * aplica o limite sustentado com recarga gradual.
     *
     * Conceito de Token Bucket:
     *   - bucket_max: tokens maximos acumulados (cap)
     *   - refill_rate: tokens recarregados por segundo
     *   - burst: tamanho do pico permitido instantaneamente
     *   - Quando o bucket esta cheio, o usuario pode fazer 'burst' requests
     *     instantaneas. Depois disso, os tokens recarregam a 'refill_rate/s'.
     *
     * Exemplo com RATE_LIMIT_MAX=5000, RATE_LIMIT_DECAY=60:
     *   - bucket_max = 5000 tokens
     *   - refill_rate = 5000/60 = ~83 tokens/segundo
     *   - burst = 100 (padrao) = pode fazer 100 requests instantaneas antes
     *     de começar a esperar recarga
     *
     * Configuravel via .env:
     *   RATE_LIMIT_MAX=5000                (general: max tokens, default 5000)
     *   RATE_LIMIT_FILE_MAX=10000          (files: max tokens, default 10000)
     *   RATE_LIMIT_METADATA_MAX=10000      (metadata: max tokens, default 10000)
     *   RATE_LIMIT_DECAY=60               (janela em segundos, default 60)
     *   RATE_LIMIT_BURST=100              (burst/pico geral, default 100)
     *   RATE_LIMIT_FILE_BURST=200         (burst/pico files, default 200)
     *   RATE_LIMIT_METADATA_BURST=200     (burst/pico metadata, default 200)
     *
     * Headers de resposta:
     *   X-RateLimit-Limit      — max tokens (cap) do bucket
     *   X-RateLimit-Remaining  — tokens disponiveis
     *   X-RateLimit-Reset      — timestamp unix ate proximo refill cheio
     *   X-RateLimit-Bucket     — nome do bucket (files/metadata/general)
     *   Retry-After            — segundos ate reset (quando 429)
     */
    public function handle(Request $request, Closure $next)
    {
        $decaySeconds = (int) env('RATE_LIMIT_DECAY', 60);

        $normalizedPath = $this->normalizePath($request->path());
        $bucket = $this->resolveBucket($normalizedPath);
        $maxTokens = $this->resolveMaxTokens($bucket);
        $burst = $this->resolveBurst($bucket);

        $key = "rate_limit:{$bucket}:" . $request->ip();
        $tokensKey = "{$key}:tokens";
        $lastRefillKey = "{$key}:last_refill";

        // Estado atual do token bucket
        $tokens = (float) Cache::get($tokensKey, (float) $burst);
        $lastRefill = (float) Cache::get($lastRefillKey, Carbon::now()->timestamp);

        // Refill: adiciona tokens com base no tempo decorrido
        $now = Carbon::now()->timestamp;
        $elapsed = $now - $lastRefill;
        $refillRate = $maxTokens / $decaySeconds; // tokens por segundo

        if ($elapsed > 0) {
            $tokens = min($tokens + ($elapsed * $refillRate), (float) $maxTokens);
        }

        // Consome 1 token
        if ($tokens < 1.0) {
            // Sem tokens — calcula retry_after
            $tokensNeeded = 1.0 - $tokens;
            $retryAfter = (int) ceil($tokensNeeded / $refillRate);
            $resetAt = $now + $retryAfter;

            $response = response()->json([
                'error' => 'Too Many Requests',
                'message' => 'Limite de requisicoes excedido. Tente novamente em breve.',
                'retry_after' => $retryAfter,
                'bucket' => $bucket,
            ], 429);

            $response->headers->set('X-RateLimit-Limit', (string) $maxTokens);
            $response->headers->set('X-RateLimit-Remaining', '0');
            $response->headers->set('X-RateLimit-Reset', (string) $resetAt);
            $response->headers->set('X-RateLimit-Bucket', $bucket);
            $response->headers->set('Retry-After', (string) $retryAfter);

            return $response;
        }

        // Consome 1 token e salva estado
        $tokens -= 1.0;
        Cache::put($tokensKey, $tokens, $decaySeconds * 2);
        Cache::put($lastRefillKey, (float) $now, $decaySeconds * 2);

        $remaining = max((int) floor($tokens), 0);

        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', (string) $maxTokens);
        $response->headers->set('X-RateLimit-Remaining', (string) $remaining);
        $response->headers->set('X-RateLimit-Reset', (string) ($now + $decaySeconds));
        $response->headers->set('X-RateLimit-Bucket', $bucket);

        return $response;
    }

    /**
     * Normaliza o path removendo prefixo de idioma.
     * /pt-BR/file/x -> /file/x
     */
    private function normalizePath(string $path): string
    {
        $segments = explode('/', $path);

        if (count($segments) > 1 && in_array($segments[1], self::LANG_PREFIXES, true)) {
            array_shift($segments);
            return '/' . implode('/', $segments);
        }

        return '/' . $path;
    }

    /**
     * Determina o bucket da rota com base no path normalizado.
     */
    private function resolveBucket(string $normalizedPath): string
    {
        foreach (self::FILE_ROUTES as $fileRoute) {
            if (str_starts_with($normalizedPath, $fileRoute)) {
                return self::BUCKET_FILE;
            }
        }

        foreach (self::METADATA_ROUTES as $metaRoute) {
            if ($normalizedPath === $metaRoute) {
                return self::BUCKET_METADATA;
            }
        }

        return self::BUCKET_GENERAL;
    }

    /**
     * Max tokens (cap) do bucket. Tokens acumulam ate esse limite.
     */
    private function resolveMaxTokens(string $bucket): int
    {
        return match ($bucket) {
            self::BUCKET_FILE => (int) env('RATE_LIMIT_FILE_MAX', 10000),
            self::BUCKET_METADATA => (int) env('RATE_LIMIT_METADATA_MAX', 10000),
            default => (int) env('RATE_LIMIT_MAX', 5000),
        };
    }

    /**
     * Burst: tokens iniciais disponiveis instantaneamente antes do refill.
     * Permite picos curtos sem throttle. Depois do burst, tokens recarregam
     * gradualmente a taxa de maxTokens/decaySeconds por segundo.
     */
    private function resolveBurst(string $bucket): int
    {
        return match ($bucket) {
            self::BUCKET_FILE => (int) env('RATE_LIMIT_FILE_BURST', 200),
            self::BUCKET_METADATA => (int) env('RATE_LIMIT_METADATA_BURST', 200),
            default => (int) env('RATE_LIMIT_BURST', 100),
        };
    }
}
