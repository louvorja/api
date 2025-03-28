<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnlineVideoChannel extends Model
{
    protected $table = 'online_videos_channels';
    protected $primaryKey = 'id_online_video_channel';
    protected $fillable = [
        'channel_id',
        'title',
        'description',
        'custom_url',
        'default_image',
        'medium_image',
        'high_image',
        'default_image_base64',
        'error',
        'status',
        'playlists',
        'id_language',
    ];

    protected $casts = [
        'playlists' => 'array',
    ];

    public function setTitleAttribute($value)
    {
        $maxLength = 100;
        $this->attributes['title'] = substr($value, 0, $maxLength);
    }
}
