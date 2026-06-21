<?php

namespace App\Http\Middleware;

use App\Models\Language;
use Cache;
use Closure;

class LangMiddleware
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
        list($lang) = explode("/", $request->path());

        $cache = config('api.cache');

        if ($lang != "") {
            if (!$cache || Cache::store('file')->get("lang_{$lang}") != true) {
                //error_log("*** Localiza idioma ***");
                if (!Language::find($lang)) {
                    return response()->json(['error' => "Idioma '$lang' não encontrado."], 401);
                }

                if ($cache) {
                    // Armazena a verificação em cache, por 24 horas
                    // para não precisar repetir consulta no BD
                    //error_log("*** Armazena em cache (lang_{$lang}) ***");
                    Cache::store('file')->put("lang_{$lang}", true, 60 * 60 * 24);
                }
            }
        }

        $request->id_language = $lang;

        return $next($request);
    }
}
