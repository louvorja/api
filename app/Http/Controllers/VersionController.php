<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

class VersionController extends Controller
{
    /**
     * Returns API version information for client compatibility checks.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[OA\Get(
        path: '/version',
        summary: 'Informações de versão da API',
        description: 'Retorna versão da API, versão mínima do cliente, versão PHP e versão do framework',
        tags: ['Public'],
        security: [],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Informações de versão',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'api_version', description: 'Versão da API', type: 'string'),
                        new OA\Property(property: 'min_client_version', description: 'Versão mínima do cliente', type: 'string'),
                        new OA\Property(property: 'php_version', description: 'Versão do PHP', type: 'string'),
                        new OA\Property(property: 'lumen_version', description: 'Versão do framework Lumen', type: 'string')
                    ]
                )
            )
        ]
    )]
    public function index()
    {
        return response()->json([
            'api_version' => config('version.version'),
            'min_client_version' => config('version.min_client_version'),
            'php_version' => PHP_VERSION,
            'lumen_version' => app()->version(),
        ]);
    }
}
