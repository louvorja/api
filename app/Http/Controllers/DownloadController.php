<?php

namespace App\Http\Controllers;

use App\Helpers\Params;
use App\Models\DownloadLog;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class DownloadController extends Controller
{
    #[OA\Get(
        path: '/download',
        operationId: 'downloadApp',
        tags: ['Public'],
        security: [],
        summary: 'Redirecionamento de download do app',
        description: 'Registra log de download e redireciona para URL do app desktop'
    )]
    #[OA\QueryParameter(
        name: 'lang',
        description: 'Código do idioma',
        required: false,
        type: 'string',
        schema: new OA\Schema(type: 'string', default: 'pt')
    )]
    #[OA\Response(
        response: 302,
        description: 'Redirecionamento para URL de download'
    )]
    public function index(Request $request)
    {
        $id_language = strtolower($request->id_language ?? $request->query('lang') ?? "pt");
        $params = Params::all();

        $url = $params[$id_language . "_download"];

        DownloadLog::create(['version' => $params[$id_language . "_version"], 'id_language' => $id_language]);

        return redirect($url);
    }
}
