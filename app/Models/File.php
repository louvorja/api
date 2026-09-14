<?php

namespace App\Models;

use App\Models\BaseModel;

class File extends BaseModel
{
    protected $primaryKey = 'id_file';
    protected $fillable = [
        'name',
        'type',
        'size',
        'host',
        'dir',
        'file_name',
        'image_position',
        'duration',
        'version',
    ];

    protected $appends = [
        'url',
    ];

    public function getUrlAttribute()
    {
        return $this->host . $this->dir . "/" . $this->file_name;
    }
}
