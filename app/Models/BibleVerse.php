<?php

namespace App\Models;

use App\Models\BaseModel;

class BibleVerse extends BaseModel
{
    protected $table = 'bible_verse';
    protected $primaryKey = 'id_bible_verse';
    protected $fillable = [
        'id_bible_version',
        'id_bible_book',
        'chapter',
        'verse',
        'text',
        'id_language',
    ];

    public function book()
    {
        return $this->belongsTo(BibleBook::class, 'id_bible_book', 'id_bible_book');
    }
}
