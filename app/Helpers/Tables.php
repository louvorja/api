<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Tables
{
    public static $system_tables = [
        "migrations",
        "configs",
        "users",
        "download_logs",
        "ftp_logs",
        "ftp",
        "logs"
    ];

    public static function all()
    {
        return Schema::getConnection()->getDoctrineSchemaManager()->listTableNames();
    }

    public static function system()
    {
        sort(self::$system_tables);
        return self::$system_tables;
    }

    public static function public()
    {
        return array_diff(self::all(), self::system());
    }
}
