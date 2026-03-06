<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DownloadLog extends Model
{
    protected $primaryKey = 'id_downalod_log';
    protected $fillable = [
        'version',
        'id_language',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($query) {
            dd(request()->ip());
            $query->ip = request()->ip();
            $query->http_client_ip = request()->server('HTTP_CLIENT_IP');
            $query->http_x_forwarded_for = request()->server('HTTP_X_FORWARDED_FOR');
            $query->remote_addr = request()->server('REMOTE_ADDR');
            $query->browser = request()->userAgent();
        });
    }
}
