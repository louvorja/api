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
     * Essas rotas usam limites mais altos porque o app desktop faz
     * batch downloads de capas, slides e musicas simultaneamente.
     */
    private const FILE_ROUTES = [
        '/file/',
        '/player',
    ];

    /**
     * Rotas de metadados leves que podem ter limites mais relaxados.
     */
    private const METADATA_ROUTES = [
        '/version',
        '/version_log',
        '/metadata',
    ];

    /**
     * Rate limiting baseado em IP com limites diferenciados por tipo de rota.
     *
     * Configurável via .env:
     *   RATE_LIMIT_MAX=300           (max requests gerais/min, default 300)
     *   RATE_LIMIT_FILE_MAX=600      (max requests para arquivos/min, default 600)
     *   RATE_LIMIT_METADATA_MAX=600  (max requests para metadados/min, default 600)
     *   RATE_LIMIT_DECAY=60          (janela em segundos, default 60)
     *
     * Headers de resposta:
     *   X-RateLimit-Limit, X-RateLimit-Remaining, X-Retry-After
     */
    public function handle(Request $request, Closure $next)
    {
        $decaySeconds = (int) env('RATE_LIMIT_DECAY', 60);
        $path = '/' . $request->path();

        $maxRequests = $this->resolveMaxRequests($path);

        $key = 'rate_limit:' . $request->ip();
        $attempts = Cache::get($key, 0);

        if ($attempts >= $maxRequests) {
            $retryAfter = Cache::get("{$key}:reset_at", Carbon::now()->addSeconds($decaySeconds)->timestamp);

            $response = response()->json([
                'error' => 'Too Many Requests',
                'message' => 'Limite de requisições excedido. Tente novamente em breve.',
                'retry_after' => $retryAfter - Carbon::now()->timestamp,
            ], 429);
            $response->headers->set('X-RateLimit-Limit', (string) $maxRequests);
            $response->headers->set('X-RateLimit-Remaining', '0');
            $response->headers->set('Retry-After', (string) ($retryAfter - Carbon::now()->timestamp));

            return $response;
        }

        Cache::put($key, $attempts + 1, $decaySeconds);

        // Define o timestamp de reset na primeira request
        if ($attempts === 0) {
            Cache::put("{$key}:reset_at", Carbon::now()->addSeconds($decaySeconds)->timestamp, $decaySeconds + 1);
        }

        $remaining = $maxRequests - ($attempts + 1);

        $response = $next($request);

        // StreamedResponse não tem ->header() (Symfony 6.4+).
        // Usa ->headers->set() que funciona em qualquer Response.
        $response->headers->set('X-RateLimit-Limit', (string) $maxRequests);
        $response->headers->set('X-RateLimit-Remaining', (string) max($remaining, 0));

        return $response;
    }

    /**
     * Determina o limite maximo de requests com base no path da rota.
     */
    private function resolveMaxRequests(string $path): int
    {
        // Rotas de arquivos (capas, slides, musicas) — limite alto
        foreach (self::FILE_ROUTES as $fileRoute) {
            if (str_starts_with($path, $fileRoute)) {
                return (int) env('RATE_LIMIT_FILE_MAX', 600);
            }
        }

        // Rotas de metadados — limite medio-alto
        foreach (self::METADATA_ROUTES as $metaRoute) {
            if ($path === $metaRoute) {
                return (int) env('RATE_LIMIT_METADATA_MAX', 600);
            }
        }

        // Rotas gerais — limite padrao
        return (int) env('RATE_LIMIT_MAX', 300);
    }
}
