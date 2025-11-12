<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PlayerController extends Controller
{
    public function __construct() {}

    public function index(Request $request)
    {
        $id = $request->v;
        $url = "https://www.youtube.com/embed/$id";

        return "
            <html>
                <head>
                    <title>Player</title>
                    <style>
                        iframe{
                            position:absolute;
                            width:100vw;
                            height:100vh;
                            top:0;
                            left:0;
                        }
                    </style>
                </head>
                <body>
                    <iframe src='$url' title='Player' frameborder='0' allow='accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share' referrerpolicy='strict-origin-when-cross-origin' allowfullscreen>
                    </iframe>
                </body>
            </html>
        ";
    }
}
