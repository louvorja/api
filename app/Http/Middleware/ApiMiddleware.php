<?php

namespace App\Http\Middleware;

use Closure;

class ApiMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $debug = config('api.debug');

        if (!$debug) {
            if (!$request->header('Api-Token')) {
                return response()->json(['error' => "Token de API não informado!"], 401);
            }
            if ($request->header('Api-Token') != config('api.token')) {
                return response()->json(['error' => "Token de API inválido!"], 401);
            }
        }

        $request->request->add(['limit' => ($request->limit ? (int) $request->limit : 100)]);
        if ($request->limit <= 0) {
            $request->request->add(['limit' => 999999]);
        }

        return $next($request);
    }
}
