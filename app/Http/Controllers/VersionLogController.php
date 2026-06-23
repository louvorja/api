<?php

namespace App\Http\Controllers;

use App\Helpers\Params;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class VersionLogController extends Controller
{
    #[OA\Get(
        path: '/version_log',
        summary: 'Changelog de versão do desktop',
        description: 'Busca release notes do GitHub para a versão informada e retorna como HTML formatado',
        tags: ['Public'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'version', description: 'Versão do app (ex: 2.5.0)', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'versao', description: 'Versão do app (legado, mesmo que version)', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'lang', description: 'Idioma', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'pt'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'HTML com release notes da versão', content: new OA\MediaType(mediaType: 'text/html'))
        ]
    )]
    public function index(Request $request)
    {
        $id_language = strtolower($request->id_language ?? $request->query('lang') ?? "pt");

        $params = Params::all();
        $version = $request->query('version') ?? $request->query('versao') ?? $params[$id_language . "_version"];

        $version_array = explode(".", $version);
        $version_software = $version_array[0] . "." . $version_array[1];

        $url = 'https://api.github.com/repos/louvorja/desktop/releases/tags/v' . $version_software;

        $response = \Illuminate\Support\Facades\Http::get($url);
        $api = json_decode($response->getBody()->getContents(), true);

        if (array_key_exists("status", $api) && $api["status"] == 404) {
            $api["body"] = "Não foi possivel encontrar informações sobre a versão $version!";
        }

        $html = "<html>";
        $html .= "<head>";
        $html .= "<style>body { padding: 20px; font-family: Arial, sans-serif; color: #666; }</style>";
        $html .= "</head>";
        $html .= "<body>";
        $html .= "<h1>$version</h1>";
        $html .= nl2br($api["body"]);
        $html .= "</body></html>";

        return $html;
    }
}
