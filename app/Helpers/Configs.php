<?php

namespace App\Helpers;

use App\Models\Config;
use Illuminate\Support\Facades\DB;

class Configs
{

    public static function get($key = "")
    {
        if (is_array($key)) {
            $config = Config::select()->whereIn("key", $key)->get();
        } elseif ($key <> "") {
            $config = Config::select()->where("key", $key)->get();
        } else {
            $config = Config::select()->get();
        }

        $data = [];
        foreach ($config as $c) {
            if ($c["type"] == "json") {
                $data[$c["key"]] = json_decode($c["value"]);
            } elseif ($c["type"] == "number") {
                $data[$c["key"]] = +$c["value"];
            } else {
                $data[$c["key"]] = $c["value"];
            }
        }

        if ($key <> "" && !is_array($key)) {
            $data = $data[$key] ?? null;
        }
        return $data;
    }

    public static function set($key, $value = "", $type = "", $details = null)
    {
        if ($type == "") {
            if (is_numeric($value)) {
                $type = "number";
            } else {
                $type = "string";
            }
        }
        Config::where('key', $key)->delete();
        Config::create(['key' => $key, 'type' => $type, 'value' => $value, 'details' => $details]);

        $config = Configs::get($key);
        return [$key => $config];
    }

    public static function refresh()
    {
        Config::where('key', 'error')->delete();

        try {
            //Cria uma transação, pois em caso de erros, deve ser feito o rollback
            DB::beginTransaction();

            $tables = DB::connection()->getDoctrineSchemaManager()->listTableNames();

            $exclude = ["migrations", "configs", "users", "download_logs", "ftp_logs", "ftp", "migrations", "logs"]; // Não checar essas tabelas
            //Obter a data e hora da alteração mais recente
            $latestUpdatedAt = null;
            foreach ($tables as $table) {
                if (in_array($table, $exclude)) {
                    continue;
                }

                try {
                    $maxUpdatedAtInTable = DB::table($table)
                        ->orderBy('updated_at', 'desc')
                        ->value('updated_at');

                    if ($maxUpdatedAtInTable > $latestUpdatedAt) {
                        $latestUpdatedAt = $maxUpdatedAtInTable;
                    }
                } catch (\Exception $e) {
                    //
                }
            }

            $version = self::get("version");
            if ($version == strtotime($latestUpdatedAt)) {
                $status = "";
                $message = null;
            } else {

                $version_number = self::get("version_number");
                if ($version_number == "") {
                    $version_number = 1;
                } else {
                    $version_number++;
                }
                self::set("version_number", $version_number++);

                Config::where('key', 'latest_updated')->delete();
                Config::create(['key' => 'latest_updated', 'type' => 'datetime', 'value' => $latestUpdatedAt]);

                Config::where('key', 'version')->delete();
                Config::create(['key' => 'version', 'type' => 'number', 'value' => strtotime($latestUpdatedAt)]);


                //Grava data e hora da atualização
                Config::where('key', 'date')->delete();
                Config::create(['key' => 'date', 'type' => 'date', 'value' => date('Y-m-d')]);
                Config::where('key', 'time')->delete();
                Config::create(['key' => 'time', 'type' => 'time', 'value' => date('H:i:s')]);
                Config::where('key', 'datetime')->delete();
                Config::create(['key' => 'datetime', 'type' => 'datetime', 'value' => date('Y-m-d H:i:s')]);


                $status = "success";
                $message = null;
            }
            DB::commit();
        } catch (\Exception $e) {
            //Rollback em caso de erros
            DB::rollback();

            Config::where('key', 'error')->delete();
            Config::create(['key' => 'error', 'type' => 'string', 'value' => $e->getMessage()]);

            $status = "error";
            $message = $e->getMessage();
        }

        return ["status" => $status, "message" => $message];
    }
}
