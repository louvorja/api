<?php

namespace App\Http\Controllers;

use App\Helpers\Data;
use App\Models\Language;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class LanguageController extends Controller
{
    public function __construct() {}

    #[OA\Get(
        path: '/{lang}/languages',
        summary: 'Listar idiomas disponíveis',
        description: 'Retorna todos os idiomas cadastrados no sistema',
        tags: ['Public'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'lang', description: 'Código do idioma', in: 'path', required: true, schema: new OA\Schema(type: 'string', default: 'pt'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de idiomas', content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object')))
        ]
    )]
    public function index(Request $request)
    {
        $model = new Language;
        $data = $model->select();

        $data = $data->distinct();
        return response()->json(Data::data($data, $request, [$model->getKeyName(), ...$model->getFillable()]));
    }
}
