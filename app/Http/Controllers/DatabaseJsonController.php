<?php

namespace App\Http\Controllers;

class DatabaseJsonController extends Controller
{
    public function __construct()
    {
        ini_set('memory_limit', '-1');
        set_time_limit(60 * 60);
    }

    public function index($file)
    {
        $file = $file . ".json";
        $filePath = base_path('public/db/json/' . $file);

        if (!file_exists($filePath)) {
            return response()->json(['error' => 'Arquivo não encontrado!', 'path' => $filePath], 404);
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return response()->json(['error' => 'Erro ao ler o arquivo!'], 500);
        }

        return response()->json(json_decode($content, true));
    }
}
