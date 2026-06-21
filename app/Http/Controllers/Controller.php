<?php

namespace App\Http\Controllers;

use Laravel\Lumen\Routing\Controller as BaseController;
use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'LouvorJA API',
    description: 'API do ecossistema LouvorJA — musicas, albums, arquivos, configs e export JSON.'
)]
#[OA\SecurityScheme(
    securityScheme: 'ApiToken',
    type: 'apiKey',
    in: 'header',
    name: 'Api-Token',
    description: 'Token de autenticacao enviado via header Api-Token'
)]
#[OA\Server(url: 'https://api.louvorja.com', description: 'Producao')]
#[OA\Server(url: 'http://localhost:8000', description: 'Desenvolvimento')]
class Controller extends BaseController
{
    //
}
