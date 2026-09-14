<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFilesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('files', function (Blueprint $table) {
            $table->increments('id_file');
            $table->string('name', 100);
            $table->string('type');
            $table->integer('size');
            $table->string('host', 200);
            $table->string('dir', 100);
            $table->string('file_name', 100);
            $table->integer('image_position')->nullable();
            $table->time('duration')->nullable();
            $table->integer('version');
            $table->timestamps();

            $table->unique(['dir', 'file_name']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('files');
    }
}
