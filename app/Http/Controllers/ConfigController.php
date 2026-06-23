<?php\n\nnamespace App\Http\Controllers;\n\nuse App\Models\Config;\nuse App\Helpers\Configs;\nuse Illuminate\Http\Request;\nuse OpenApi\Attributes as OA;\n\nclass ConfigController extends Controller\n{\n    public function __construct()\n    {\n\n    }\n\n    #[OA\Get(
        path: '/configs',
        summary: 'Listar configurações',
        description: 'Retorna configurações do sistema',
        tags: ['Admin - Configurações'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Configurações', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Não autenticado')
        ]
    )]\n    public function index(Request $request)\n    {\n        //Verifica se já foi feita atualização no dia, e faz em caso de negativa\n        $datetime = Config::select()->where('key', 'date')->where('value', date('Y-m-d'))->first();\n        if (!$datetime) {\n            Configs::refresh();\n        }\n        return $this->configs();\n\n    }\n    #[OA\Post(
        path: '/configs/refresh',
        summary: 'Recarregar configurações',
        description: 'Força recarregamento do cache de configurações',
        tags: ['Admin - Configurações'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Cache recarregado'),
            new OA\Response(response: 401, description: 'Não autenticado')
        ]
    )]\n    public function refresh()\n    {\n        Configs::refresh();\n        return $this->configs();\n    }\n\n    #[OA\Get(
        path: '/configs/list',
        summary: 'Listar chaves de configuração',
        description: 'Retorna lista de chaves de configuração disponíveis',
        tags: ['Admin - Configurações'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Lista de chaves', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Não autenticado')
        ]
    )]\n    public function configs()\n    {\n        $data = Configs::get();\n        return response()->json(["data" => $data]);\n    }\n}