<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;

class DatabaseJsonController extends Controller
{
    public function __construct()
    {
        ini_set('memory_limit', '-1');
        set_time_limit(60 * 60);
    }

    /**
     * Lista todos os arquivos JSON disponiveis para download.
     * Retorna nome (sem extensao) e tamanho em bytes de cada arquivo.
     */
    public function manifest()
    {
        $dir = base_path('public/db/json/');

        if (!File::exists($dir)) {
            return response()->json([
                'files' => [],
                'total' => 0,
            ]);
        }

        $files = File::files($dir);
        $result = [];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            $result[] = [
                'name' => $file->getBasename('.json'),
                'size' => $file->getSize(),
            ];
        }

        return response()->json([
            'files' => $result,
            'total' => count($result),
        ]);
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
