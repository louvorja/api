<?php\n\nnamespace App\Http\Controllers;\n\nuse App\Helpers\Data;\nuse App\Models\Language;\nuse Illuminate\Http\Request;\nuse OpenApi\Attributes as OA;\n\nclass LanguageController extends Controller\n{\n    public function __construct() {}\n\n    #[OA\Get(
        path: '/languages',
        summary: 'Listar idiomas disponíveis',
        description: 'Retorna todos os idiomas cadastrados no sistema',
        tags: ['Public'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'lang', description: 'Idioma', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'pt'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de idiomas', content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object')))
        ]
    )]\n    public function index(Request $request)\n    {\n        $model = new Language;\n        $data = $model->select();\n\n        $data = $data->distinct();\n        return response()->json(Data::data($data, $request, [$model->getKeyName(), ...$model->getFillable()]));\n    }\n}