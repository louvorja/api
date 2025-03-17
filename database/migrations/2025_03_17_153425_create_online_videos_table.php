<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOnlineVideosTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('online_videos', function (Blueprint $table) {
            $table->increments('id_online_video');
            $table->unsignedInteger('id_online_video_playlist');
            $table->string('video_id', 50)->unique();
            $table->string('title', 100)->nullable();
            $table->text('description')->nullable();
            $table->integer('sequence')->nullable();
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

            $table->foreign('id_online_video_playlist')->references('id_online_video_playlist')->on('online_videos_playlists')->onUpdate('cascade')->onDelete('cascade');
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
        Schema::dropIfExists('online_videos');
    }
}
