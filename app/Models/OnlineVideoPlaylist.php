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
        'title',
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

    public function setTitleAttribute($value)
    {
        $maxLength = 100;
        $this->attributes['title'] = substr($value, 0, $maxLength);
    }

    public function channel()
    {
        return $this->belongsTo(OnlineVideoChannel::class, 'id_online_video_channel', 'id_online_video_channel');
    }
}
