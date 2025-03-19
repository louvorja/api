<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnlineVideo extends Model
{
    protected $table = 'online_videos';
    protected $primaryKey = 'id_online_video';
    protected $fillable = [
        'id_online_video_playlist',
        'video_id',
        'title',
        'description',
        'sequence',
        'default_image',
        'medium_image',
        'high_image',
        'standard_image',
        'maxres_image',
        'default_image_base64',
        'error',
        'status',
        'id_language',
    ];

    public function setTitleAttribute($value)
    {
        $maxLength = 100;
        $this->attributes['title'] = substr($value, 0, $maxLength);
    }

    public function playlist()
    {
        return $this->belongsTo(OnlineVideoPlaylist::class, 'id_online_video_playlist', 'id_online_video_playlist');
    }
}
