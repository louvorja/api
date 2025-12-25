<?php

namespace App\Http\Controllers;

use App\Helpers\Configs;
use App\Helpers\Files;
use App\Helpers\OnlineVideos;
use App\Helpers\DataBase;
use App\Helpers\Ftp;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct()
    {
        ini_set('memory_limit', '-1');
        set_time_limit(60 * 60);
    }

    public function refresh_files_size($check_version = true)
    {
        if ($check_version) {
            $version = Configs::get("version");
            $last_version = Configs::get("version_files_size");
            if ($last_version == $version) {
                return;
            }
        }
        $ret = Files::refresh_size();
        Configs::set("version_files_size", $version);
        return $ret;
    }

    public function refresh_files_duration($check_version = true)
    {
        if ($check_version) {
            $version = Configs::get("version");
            $last_version = Configs::get("version_files_duration");
            if ($last_version == $version) {
                return;
            }
        }
        $ret = Files::refresh_duration();
        Configs::set("version_files_duration", $version);
        return $ret;
    }

    public function refresh_online_videos()
    {
        $ret = OnlineVideos::refresh();
        if ($ret["status"] == "") {
            $ret = [];
        }
        return $ret;
    }

    public function refresh_configs()
    {
        $ret = Configs::refresh();
        if ($ret["status"] <> "") {
            $data = Configs::get();
            $ret["data"] = $data;
        } else {
            $ret = [];
        }

        return $ret;
    }

    public function export_database($check_version = true)
    {
        if ($check_version) {
            $version = Configs::get("version");
            $last_version = Configs::get("version_export_database");
            if ($last_version == $version) {
                return;
            }
        }

        $ret = DataBase::export();
        Configs::set("version_export_database", $version);
        return $ret;
    }

    public function export_database_json($check_version = true)
    {
        if ($check_version) {
            $version = Configs::get("version");
            $last_version = Configs::get("version_export_database_json");
            if ($last_version == $version) {
                return;
            }
        }

        $ret = DataBase::export_json();
        if ($ret["error"]) {
            Configs::set("version_export_database_json", -1);
        } else {
            Configs::set("version_export_database_json", $version);
        }
        return $ret;
    }

    public function send_database_ftp($check_version = true)
    {
        if ($check_version) {
            $version = Configs::get("version");
            $last_version = Configs::get("version_send_database_ftp");
            if ($last_version == $version) {
                return;
            }
        }

        $ret = Ftp::send_database();
        if ($ret["status"] == true) {
            Configs::set("version_send_database_ftp", $version);
        }
        return $ret;
    }

    public function import_slides()
    {
        $dir = app()->basePath('public') . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR;

        $files = Files::list_files($dir);

        if (isset($files["error"])) {
            return response()->json($files);
        }

        $log = [];
        foreach ($files as $file) {
            $ret = DataBase::import_file($file["path"]);
            $log[] = ['file' => $file['name'], 'status' => $ret];
        }

        return response()->json($log);
    }

    public function index(Request $request)
    {
        /*  Configs::refresh();

        $version = Configs::get("version");
        $last_version = Configs::get("last_version");
        $force = ($request->force ?? 0);
        $logs = [];

        if ($force == 1 || $last_version <> $version) {

            //Teve alterações no banco de dados. Gera os dados novamente

            //Ajusta tamanho dos arquivos, caso tenham novos arquivos
            $logs["refresh_files_size"] = Files::refresh_size();

            //Exporta o banco de dados
            $logs["export_database"] = DataBase::export();


            //Atualiza a versão anterior para ficar igual a atual
            $logs["new_version"] = Configs::set("last_version", $version);
        }

        $data = Configs::get();
        return response()->json(["logs" => $logs, "data" => $data]);*/

        return response()->json([]);
    }
}
