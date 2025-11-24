<?php

namespace App\Helpers;

use App\Helpers\Configs;
use Firebase\JWT\JWT;
use App\Models\Language;

class Params
{

    public static function all()
    {

        $file_name = [
            "pt" => "LouvorJA_Instalador",
            "es" => "LoorJA_Instalador"
        ];

        $params = [];

        $langs = Language::all();
        foreach ($langs as $lang) {
            $id_language = $lang->id_language;

            $version = Configs::get($id_language . "_delphi_version"); // mudar depois para ser 2 digitos ex.: 26.0
            $params["versao" . strtoupper($id_language)] = $version . ".0.0"; // remover depois -- adaptar no Delphi primeiro (removido na versao 26)
            $params["instalador" . strtoupper($id_language)] = "setup\Output\\" . $file_name[$id_language] . $version . ".exe"; // remover depois -- adaptar no Delphi primeiro  (removido na versao 26)

            $params[$id_language . "_version"] = $version;

            $version_array = explode(".", $version);
            $version_software = $version_array[0] . "." . $version_array[1];
            $params[$id_language . "_version_software"] = $version_software;

            $params[$id_language . "_setup_name"] = $file_name[$id_language] . $version_software . ".exe";

            $params["setup_name" . strtoupper($id_language)] = $file_name[$id_language] . $version . ".exe"; // remover depois -- adaptar no Delphi primeiro
            if ($version_array[0] >= "26") {
                $params[$id_language . "_download"] = "https://github.com/louvorja/desktop/releases/download/v" . $version_software . "/" . $params[$id_language . "_setup_name"];
            } else {
                $params[$id_language . "_download"] = "https://github.com/louvorja/desktop/releases/download/v" . $version_software . "/" . $params["setup_name" . strtoupper($id_language)]; // remover depois -- adaptar no Delphi primeiro (removido na versao 26)
            }

            $params["download" . strtoupper($id_language)] = $params[$id_language . "_download"]; // remover depois -- adaptar no Delphi primeiro


            if ($lang->id_language == "pt") {
                $params["versao"] = $version . ".0.0"; // remover depois -- adaptar no Delphi primeiro (removido na versao 26)
                $params["instalador"] = $params["instalador" . strtoupper($id_language)]; // remover depois -- adaptar no Delphi primeiro (removido na versao 26)
                $params["download"] = $params["download" . strtoupper($id_language)]; // remover depois -- adaptar no Delphi primeiro
                $params["setup_name"] = $params["setup_name" . strtoupper($id_language)]; // remover depois -- adaptar no Delphi primeiro
            }
        }

        $params["db_version"] = Configs::get("version_number_ftp");

        $token_ftp = JWT::encode(['exp' => time() + 120], env('JWT_SECRET'), 'HS256');
        $params["conn_ftp"] = "https://api.louvorja.com.br/ftp?token=" . $token_ftp;
        $params["version_log"] = "https://api.louvorja.com.br/version_log";
        $params["help"] = "https://louvorja.com.br/ajuda/";

        /* A partir daqui, são todos parâmetros do Delphi */
        $params["coletaneas_online"] = "https://api.louvorja.com.br/onlinevideos";
        $params["embed_youtube"] = "https://www.youtube.com/embed/{videoID}";
        //$params["ftp"] = "https://api.louvorja.com.br/ftp"; // REMOVER DEPOIS PARA MANTER A FORMA SEGURA (COM TOKEN)
        $params["helpPT"] = $params["help"] . "?lang=pt";
        $params["helpES"] = $params["help"] . "?lang=es";
        $params["logs_versao"] = $params["version_log"];

        return $params;
    }
}
