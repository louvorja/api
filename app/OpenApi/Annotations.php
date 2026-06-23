<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: 'LouvorJA API',
    version: '1.0.0',
    description: 'API do LouvorJA - Gerenciador de Hinários e Músicas para a Igreja Adventista do Sétimo Dia. Fornece endpoints públicos para consulta de músicas, letras, arquivos e álbuns, além de endpoints administrativos protegidos por autenticação JWT.',
    contact: new OA\Contact(
        name: 'LouvorJA',
        email: 'contato@louvorja.com'
    )
)]
#[OA\Server(
    url: '/api',
    description: 'API Base URL'
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
    description: 'Autenticação JWT via header Authorization: Bearer {token}'
)]
class Annotations
{
}
