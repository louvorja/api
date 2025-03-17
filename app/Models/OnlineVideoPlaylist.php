<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnlineVideoPlaylist extends Model
{
    protected $table = 'online_videos_playlists';
    protected $primaryKey = 'id_online_video_playlist';
    protected $fillable = [
        'channel_id',
        'name',
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
}
