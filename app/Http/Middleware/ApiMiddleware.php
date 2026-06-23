<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;

class ApiMiddleware
{
    /**
     * Handle an incoming request.
     *
     * Validates Api-Token header against configured tokens.
     * Supports multiple keys with labels (config/api.tokens)
     * Falls back to single key (config/api.token) for backward compatibility.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $debug = config('api.debug');

        if (!$debug) {
            $token = $request->header('Api-Token');

            if (!$token) {
                return response()->json(['error' => 'Token de API não informado!', 'code' => 401], 401);
            }

            $valid = $this->validateToken($token, $request);

            if (!$valid) {
                return response()->json(['error' => 'Token de API inválido!', 'code' => 401], 401);
            }
        }

        $request->request->add(['limit' => ($request->limit ? (int) $request->limit : 100)]);
        if ($request->limit <= 0) {
            $request->request->add(['limit' => 999999]);
        }

        return $next($request);
    }

    /**
     * Validate API token against configured keys.
     *
     * When api.tokens is set (array of label => token), matches against all values.
     * Otherwise falls back to the single api.token string (backward compat).
     *
     * Sets request attributes with the matched key label for downstream use.
     */
    private function validateToken(string $token, $request): bool
    {
        $multiTokens = config('api.tokens', []);

        if (!empty($multiTokens) && is_array($multiTokens)) {
            foreach ($multiTokens as $label => $key) {
                if ($key !== '' && hash_equals($key, $token)) {
                    $request->attributes->set('api_key_label', $label);
                    return true;
                }
            }
            return false;
        }

        // Fallback: single token (backward compat)
        $singleToken = config('api.token', '');
        if ($singleToken !== '' && hash_equals($singleToken, $token)) {
            $request->attributes->set('api_key_label', 'default');
            return true;
        }

        return false;
    }
}
