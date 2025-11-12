<?php

namespace App\Http\Controllers;

use App\Helpers\Params;
use Illuminate\Http\Request;


class VersionLogController extends Controller
{
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
        $html .= nl2br(htmlspecialchars($api["body"], ENT_QUOTES, 'UTF-8'));
        $html .= "</body></html>";

        return $html;
    }
}
