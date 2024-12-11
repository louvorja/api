<?php

namespace App\Models;

use App\Models\BaseModel;

class BibleVersion extends BaseModel
{
    protected $table = 'bible_version';
    protected $primaryKey = 'id_bible_version';
    protected $fillable = [
        'name',
        'abbreviation',
        'id_language',
    ];
}
