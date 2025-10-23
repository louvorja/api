<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Storage;
use App\Helpers\Configs;
use App\Models\Language;
use App\Models\Ftp as FtpModel;

class Ftp
{

    public static function send_database()
    {

        $ret = ["status" => true];
        $langs = Language::orderBy("id_language", "desc")->get();

        foreach ($langs as $lang) {
            $id_language = $lang->id_language;

            $ret[$id_language]["lang"] = $id_language;
            $ftp_list = FtpModel::select()->where('id_language', $id_language)->get();
            foreach ($ftp_list as $ftp_item) {
                $data = $ftp_item->data;

                $ret[$id_language]["ftp"][$ftp_item->id_ftp]["id_ftp"] = $ftp_item->id_ftp;

                $file = Configs::get($id_language . "_path_database");
                if ($file == "") {
                    $ret[$id_language]["ftp"][$ftp_item->id_ftp]["error"] = "Caminho do banco de dados não encontrado.";
                    $ret["status"] = false;
                    $ret["message"] = "Caminho do banco de dados não encontrado.";
                    continue;
                }

                try {
                    $ftp = Storage::build([
                        'driver'   => 'ftp',
                        'host'     => $data["host"],
                        'username' => $data["username"],
                        'password' => $data["password"],
                        'root'     => ($data["root"] ?? '/') . 'config',
                        'port'     => $data["port"] ?? 21,
                        'passive'  => true,
                        'ssl'      => false,
                        'timeout'  => 30,
                    ]);


                    $ftp->put('database.db', fopen($file, 'r+'));
                    $ret[$id_language]["file"] = $file;
                } catch (\Throwable $e) {
                    $ret[$id_language]["ftp"][$ftp_item->id_ftp]["error"] = $e->getMessage();
                    $ret["status"] = false;
                    $ret["message"] = $e->getMessage();
                }
            }
        }

        if ($ret["status"] == true) {
            Configs::set("version_number_ftp", Configs::get("version_number"));
        }

        return $ret;
    }
}
