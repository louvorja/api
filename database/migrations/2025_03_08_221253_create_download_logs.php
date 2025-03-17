<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDownloadLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('download_logs', function (Blueprint $table) {
            $table->increments('id_download_log');
            $table->string('version', 20);
            $table->string('ip', 20)->nullable();
            $table->string('http_client_ip', 20)->nullable();
            $table->string('http_x_forwarded_for', 20)->nullable();
            $table->string('remote_addr', 20)->nullable();
            $table->string('browser', 255)->nullable();
            $table->string('id_language', 5);
            $table->timestamps();

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
        Schema::dropIfExists('download_logs');
    }
}
