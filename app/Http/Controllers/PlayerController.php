<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class PlayerController extends Controller
{
    public function __construct() {}

    #[OA\Get(
        path: '/player',
        operationId: 'getYouTubePlayer',
        tags: ['Public'],
        security: [],
        summary: 'Player de vídeo YouTube',
        description: 'Retorna HTML com iframe embed do YouTube para o vídeo informado',
        parameters: [
            new OA\Parameter(
                name: 'v',
                description: 'ID do vídeo no YouTube',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(response: 200, description: 'HTML do player')
        ]
    )]
    public function index(Request $request)
    {
        $id = $request->v;
        $url = "https://www.youtube.com/embed/$id";

        return "
        <html>
        <head>
        <title>Player</title>
        <style>
        html,body{
            margin:0;
            padding:0;
            background:#000;
        }
        iframe{
            position:absolute;
            width:100%;
            height:100%;
            top:0;
            left:0;
            border:0;
        }
        </style>
        </head>
        <body>
        <iframe src='$url' title='Player' frameborder='0' allow='accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share' referrerpolicy='strict-origin-when-cross-origin' allowfullscreen>
        </iframe>
        </body>
        <html>
        ";
    }
}
