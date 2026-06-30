<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class RateLimitMiddleware
{
    /**
     * Rate limiting baseado em IP.
     *
     * Configurável via .env:
     *   RATE_LIMIT_MAX=60        (máximo de requests, default 60)
     *   RATE_LIMIT_DECAY=60      (janela em segundos, default 60)
     *   RATE_LIMIT_PER_MINUTE=60 (alias)
     *
     * Headers de resposta:
     *   X-RateLimit-Limit, X-RateLimit-Remaining, X-Retry-After
     */
    public function handle(Request $request, Closure $next)
    {
        $maxRequests = (int) env('RATE_LIMIT_MAX', env('RATE_LIMIT_PER_MINUTE', 60));
        $decaySeconds = (int) env('RATE_LIMIT_DECAY', 60);

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
}
