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
        'base_dir',
        'base_url',
        'subdirectory',
        'file_name',
        'image_position',
        'version',
    ];

    protected $appends = [
        'url',
    ];

    public function getUrlAttribute()
    {
        return $this->base_url . $this->subdirectory . $this->file_name;
    }
}
