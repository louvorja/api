<?php

namespace App\Http\Controllers;

use App\Helpers\Params;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ParamsController extends Controller
{
    #[OA\Get(
        path: '/params',
        summary: 'Parâmetros da aplicação',
        description: 'Retorna parâmetros de configuração da aplicação em formato JSON ou .env',
        tags: ['API'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'type', description: 'Formato de resposta', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['json', 'env'], default: 'json'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Parâmetros da aplicação (formato depende do parâmetro type)', content: new OA\JsonContent(type: 'object'))
        ]
    )]
    public function index(Request $request)
    {
        $type = $request->get("type") ?? "json";

        $params = Params::all();

        if ($type == "env") {
            $text = "";
            foreach ($params as $key => $param) {
                $text .= "$key=$param\r\n";
            }
            return response($text, 200)->header('Content-Type', 'text/plain');
        } else {
            return response()->json($params);
        }
    }
}
