<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FtpLog extends Model
{
    protected $table = 'ftp_logs';
    protected $primaryKey = 'id_ftp_log';
    protected $fillable = [
        'id_ftp',
        'version',
        'bin_version',
        'datetime',
        'directory',
        'pc_name',
        'local_ip',
        'ip',
        'http_client_ip',
        'http_x_forwarded_for',
        'remote_addr',
        'browser',
        'request',
        'error',
        'id_language'
    ];

    protected $casts = [
        'request' => 'array',
        'error' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($query) {
            $query->ip = request()->ip();
            $query->http_client_ip = request()->server('HTTP_CLIENT_IP');
            $query->http_x_forwarded_for = request()->server('HTTP_X_FORWARDED_FOR');
            $query->remote_addr = request()->server('REMOTE_ADDR');
            $query->browser = request()->userAgent();
        });
    }
}
