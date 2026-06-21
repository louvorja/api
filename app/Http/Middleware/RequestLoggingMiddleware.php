<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;

class RequestLoggingMiddleware
{
    /**
     * Log every API request with structured context.
     *
     * Logs method, path, status, duration, IP, and user agent.
     * For authenticated requests, includes the user identifier.
     */
    public function handle(Request $request, Closure $next)
    {
        $start = microtime(true);
        $response = $next($request);
        $duration = round((microtime(true) - $start) * 1000, 2);

        $context = [
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $duration,
            'ip' => $request->ip(),
        ];

        // Extract user from JWT token if present
        try {
            $token = JWTAuth::getToken();
            if ($token) {
                $context['user'] = JWTAuth::getPayload($token)->get('sub');
            }
        } catch (TokenExpiredException | TokenInvalidException $e) {
            $context['auth_error'] = $e->getMessage();
        } catch (\Exception $e) {
            // No token present — skip
        }

        $level = $this->logLevel($response->getStatusCode(), $duration);

        Log::channel('daily')->{$level}('API Request', $context);

        return $response;
    }

    /**
     * Determine log level based on HTTP status code and duration.
     */
    private function logLevel(int $status, float $durationMs): string
    {
        if ($status >= 500) {
            return 'error';
        }

        if ($status >= 400) {
            return 'warning';
        }

        // Slow requests (over 2 seconds) even if successful
        if ($durationMs > 2000) {
            return 'warning';
        }

        return 'info';
    }
}
