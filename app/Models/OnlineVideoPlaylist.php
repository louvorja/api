<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnlineVideoPlaylist extends Model
{
    protected $table = 'online_videos_playlists';
    protected $primaryKey = 'id_online_video_playlist';
    protected $fillable = [
        'id_online_video_channel',
        'playlist_id',
        'name',
        'description',
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
}
