<?php

namespace App\Http\Controllers;

use App\Models\Config;
use App\Helpers\Configs;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ConfigController extends Controller
{
    public function __construct()
    {

    }

    #[OA\Get(
        path: '/{lang}/config',
        summary: 'Listar configurações',
        description: 'Retorna configurações do sistema para o idioma informado',
        tags: ['Public'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'lang', description: 'Código do idioma', in: 'path', required: true, schema: new OA\Schema(type: 'string', default: 'pt'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Configurações', content: new OA\JsonContent(type: 'object'))
        ]
    )]
    public function index(Request $request)
    {
        //Verifica se já foi feita atualização no dia, e faz em caso de negativa
        $datetime = Config::select()->where('key', 'date')->where('value', date('Y-m-d'))->first();
        if (!$datetime) {
            Configs::refresh();
        }
        return $this->configs();

    }

    #[OA\Get(
        path: '/{lang}/configs',
        summary: 'Listar configurações (alias)',
        description: 'Alias para /{lang}/config. Retorna configurações do sistema',
        tags: ['Public'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'lang', description: 'Código do idioma', in: 'path', required: true, schema: new OA\Schema(type: 'string', default: 'pt'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Configurações', content: new OA\JsonContent(type: 'object'))
        ]
    )]
    public function configs()
    {
        $data = Configs::get();
        return response()->json(["data" => $data]);
    }
}
