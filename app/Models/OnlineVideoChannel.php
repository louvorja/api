<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnlineVideoChannel extends Model
{
    protected $table = 'online_videos_channels';
    protected $primaryKey = 'id_online_video_channel';
    protected $fillable = [
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
