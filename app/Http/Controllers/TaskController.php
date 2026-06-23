<?php\n\nnamespace App\Http\Controllers;\n\nuse App\Helpers\Configs;\nuse App\Helpers\Files;\nuse App\Helpers\OnlineVideos;\nuse App\Helpers\DataBase;\nuse App\Helpers\Ftp;\nuse Illuminate\Http\Request;\nuse OpenApi\Attributes as OA;\n\nclass TaskController extends Controller\n{\n    public function __construct()\n    {\n        ini_set('memory_limit', '-1');\n        set_time_limit(60 * 60);\n    }\n\n    #[OA\Post(
        path: '/tasks/refresh-files-size',
        summary: 'Recalcular tamanho dos arquivos',
        description: 'Recalcula o tamanho de todos os arquivos de mídia armazenados',
        tags: ['Admin - Tarefas'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Tamanhos atualizados'),
            new OA\Response(response: 401, description: 'Não autenticado')
        ]
    )]\n    public function refresh_files_size($check_version = true)\n    {\n        if ($check_version) {\n            $version = Configs::get("version");\n            $last_version = Configs::get("version_files_size");\n            if ($last_version == $version) {\n                return;\n            }\n        }\n        $ret = Files::refresh_size();\n        Configs::set("version_files_size", $version);\n        return $ret;\n    }\n\n    #[OA\Post(
        path: '/tasks/refresh-files-duration',
        summary: 'Recalcular duração dos áudios',
        description: 'Recalcula a duração de todos os arquivos de áudio',
        tags: ['Admin - Tarefas'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Durações atualizadas'),
            new OA\Response(response: 401, description: 'Não autenticado')
        ]
    )]\n    public function refresh_files_duration($check_version = true)\n    {\n        if ($check_version) {\n            $version = Configs::get("version");\n            $last_version = Configs::get("version_files_duration");\n            if ($last_version == $version) {\n                return;\n            }\n        }\n        $ret = Files::refresh_duration();\n        Configs::set("version_files_duration", $version);\n        return $ret;\n    }\n\n    #[OA\Post(
        path: '/tasks/refresh-online-videos',
        summary: 'Recarregar vídeos online',
        description: 'Atualiza dados de vídeos online do YouTube',
        tags: ['Admin - Tarefas'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Vídeos atualizados'),
            new OA\Response(response: 401, description: 'Não autenticado')
        ]
    )]\n    public function refresh_online_videos()\n    {\n        $ret = OnlineVideos::refresh();\n        if ($ret["status"] == "") {\n            $ret = [];\n        }\n        return $ret;\n    }\n\n    #[OA\Post(
        path: '/tasks/refresh-configs',
        summary: 'Recarregar configurações',
        description: 'Recarrega o cache de configurações do sistema',
        tags: ['Admin - Tarefas'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Configurações recarregadas'),
            new OA\Response(response: 401, description: 'Não autenticado')
        ]
    )]\n    public function refresh_configs()\n    {\n        $ret = Configs::refresh();\n        if ($ret["status"] <> "") {\n            $data = Configs::get();\n            $ret["data"] = $data;\n        } else {\n            $ret = [];\n        }\n\n        return $ret;\n    }\n\n    #[OA\Post(
        path: '/tasks/export-database',
        summary: 'Exportar banco de dados (SQL)',
        description: 'Gera exportação SQL do banco de dados para o desktop app',
        tags: ['Admin - Tarefas'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Exportação concluída', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Não autenticado')
        ]
    )]\n    public function export_database($check_version = true)\n    {\n        if ($check_version) {\n            $version = Configs::get("version");\n            $last_version = Configs::get("version_export_database");\n            if ($last_version == $version) {\n                return;\n            }\n        }\n\n        $ret = DataBase::export();\n        if ($ret["error"] && $ret["error"] <> "") {\n            Configs::set("version_export_database", -1);\n        } else {\n            Configs::set("version_export_database", $version);\n        }\n        return $ret;\n    }\n\n    #[OA\Post(
        path: '/tasks/export-database',
        summary: 'Exportar banco de dados (SQL)',
        description: 'Gera exportação SQL do banco de dados para o desktop app',
        tags: ['Admin - Tarefas'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Exportação concluída', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Não autenticado')
        ]
    )]\n    public function export_database_json($check_version = true)\n    {\n        if (request("force") && request("force") == "true") {\n            $check_version = false;\n        }\n\n        if ($check_version) {\n            $version = Configs::get("version");\n            $last_version = Configs::get("version_export_database_json");\n            if ($last_version == $version) {\n                return [];\n            }\n        }\n\n        $ret = DataBase::export_json();\n        if ($check_version) {\n            Configs::set("version_export_database_json", $version);\n        }\n        return $ret;\n    }\n\n    #[OA\Post(
        path: '/tasks/send-database-ftp',
        summary: 'Enviar banco via FTP',
        description: 'Envia exportação do banco de dados para os servidores FTP configurados',
        tags: ['Admin - Tarefas'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Envio concluído'),
            new OA\Response(response: 401, description: 'Não autenticado')
        ]
    )]\n    public function send_database_ftp($check_version = true)\n    {\n        if ($check_version) {\n            $version = Configs::get("version");\n            $last_version = Configs::get("version_send_database_ftp");\n            if ($last_version == $version) {\n                return;\n            }\n        }\n\n        Files::permissions(config("files.dir"), 0644, 0755);\n        $ret = Ftp::send_database();\n        Files::permissions(config("files.dir"), 0444, 0555);\n        if ($ret["status"] == true) {\n            Configs::set("version_send_database_ftp", $version);\n        }\n        return $ret;\n    }\n\n    #[OA\Post(
        path: '/tasks/import-slides',
        summary: 'Importar slides',
        description: 'Importa slides de apresentações de um diretório configurado',
        tags: ['Admin - Tarefas'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Importação concluída'),
            new OA\Response(response: 401, description: 'Não autenticado')
        ]
    )]\n    public function import_slides()\n    {\n        $dir = app()->basePath('public') . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR;\n\n        $files = Files::list_files($dir);\n\n        if (isset($files["error"])) {\n            return response()->json($files);\n        }\n\n        $log = [];\n        foreach ($files as $file) {\n            $ret = DataBase::import_file($file["path"]);\n            $log[] = ['file' => $file['name'], 'status' => $ret];\n        }\n\n        return response()->json($log);\n    }\n\n    #[OA\Get(
        path: '/tasks',
        summary: 'Listar tarefas',
        description: 'Retorna lista de tarefas administrativas disponíveis',
        tags: ['Admin - Tarefas'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Lista de tarefas', content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))),
            new OA\Response(response: 401, description: 'Não autenticado')
        ]
    )]\n    public function index(Request $request)\n    {\n        /*  Configs::refresh();\n\n        $version = Configs::get("version");\n        $last_version = Configs::get("last_version");\n        $force = ($request->force ?? 0);\n        $logs = [];\n\n        if ($force == 1 || $last_version <> $version) {\n\n            //Teve alterações no banco de dados. Gera os dados novamente\n\n            //Ajusta tamanho dos arquivos, caso tenham novos arquivos\n            $logs["refresh_files_size"] = Files::refresh_size();\n\n            //Exporta o banco de dados\n            $logs["export_database"] = DataBase::export();\n\n\n            //Atualiza a versão anterior para ficar igual a atual\n            $logs["new_version"] = Configs::set("last_version", $version);\n        }\n\n        $data = Configs::get();\n        return response()->json(["logs" => $logs, "data" => $data]);*/\n\n        return response()->json([]);\n    }\n}