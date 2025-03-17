<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class OnlineVideosPlaylistsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('online_videos_playlists', function (Blueprint $table) {
            $table->increments('id_online_video_playlist');
            $table->unsignedInteger('id_online_video_channel');
            $table->string('playlist_id', 50)->unique();
            $table->string('name', 50)->nullable();
            $table->text('description')->nullable();
            $table->string('default_image', 200)->nullable();
            $table->string('medium_image', 200)->nullable();
            $table->string('high_image', 200)->nullable();
            $table->string('standard_image', 200)->nullable();
            $table->string('maxres_image', 200)->nullable();
            $table->text('default_image_base64')->nullable();
            $table->string('error')->nullable();
            $table->enum('status', ['pending', 'validated', 'error'])->default('pending');
            $table->string('id_language', 5);
            $table->timestamps();

            $table->foreign('id_online_video_channel')->references('id_online_video_channel')->on('online_videos_channels')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('id_language')->references('id_language')->on('languages');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('online_videos_playlists');
    }
}
