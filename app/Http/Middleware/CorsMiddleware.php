<?php

namespace App\Http\Middleware;

use Closure;

class CorsMiddleware
{
    /**
     * Handle an incoming request.
     * Suporta whitelist de origens via env CORS_ALLOWED_ORIGINS.
     * Default: '*' (compativel com comportamento atual).
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $allowedOrigin = $this->resolveOrigin($request);

        $headers = [
            'Access-Control-Allow-Origin' => $allowedOrigin,
            'Access-Control-Allow-Methods' => 'POST, GET, OPTIONS, PUT, DELETE',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Max-Age' => '86400',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, Api-Token',
        ];

        if ($request->isMethod('OPTIONS')) {
            return response()->json('{"method":"OPTIONS"}', 204, $headers);
        }

        $response = $next($request);

        foreach ($headers as $key => $value) {
            $response->headers->set($key, $value);
        }

        return $response;
    }

    /**
     * Resolve a origem permitida.
     * Se CORS_ALLOWED_ORIGINS for '*', retorna '*'.
     * Caso contrario, valida Origin do request contra a whitelist.
     */
    private function resolveOrigin($request)
    {
        $allowed = env('CORS_ALLOWED_ORIGINS', '*');

        if ($allowed === '*') {
            return '*';
        }

        $origin = $request->header('Origin');
        $origins = array_map('trim', explode(',', $allowed));

        if ($origin && in_array($origin, $origins)) {
            return $origin;
        }

        // Origem nao permitida — retorna null header
        return 'null';
    }
}
