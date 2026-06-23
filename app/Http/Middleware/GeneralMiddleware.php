<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class GeneralMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $userAgent = $request->header('User-Agent');
        if (str_contains($userAgent, 'bot') || str_contains($userAgent, 'crawler')) {
            return response('Access denied for bots', 403);
        }

        $host = $request->getHost();
        $path = $request->path();

        $host_parts = explode(".", $host);
        $subdomain = $host_parts[0];
        if ($subdomain === 'www') {
            array_shift($host_parts);
            $subdomain = $host_parts[0];
        }

        // Verifica a requisição da url
        // Libera acesso direto por IP (desenvolvimento)
        $isDirectIp = (bool) preg_match('/^\d+\.\d+\.\d+\.\d+/', $host);

        if ($subdomain !== 'api' && $subdomain !== 'localhost' && !$isDirectIp) {
            $locale = 'pt'; // Idioma padrão

            // Verifica se a URL contém um segmento de idioma (ex: /es)
            if (preg_match('/^es(\/|$)/', $path)) {
                $locale = 'es';
            }

            // Redireciona para a URL correta na API
            return redirect("https://api.louvorja.com.br/{$locale}/{$subdomain}");
        }

        return $next($request);
    }
}
