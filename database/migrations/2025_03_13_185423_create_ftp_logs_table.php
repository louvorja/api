<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFtpLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ftp_logs', function (Blueprint $table) {
            $table->increments('id_ftp_logs');
            $table->unsignedInteger('id_ftp')->nullable();
            $table->string('version', 20)->nullable();
            $table->string('bin_version', 20)->nullable();
            $table->datetime('datetime')->nullable();
            $table->string('directory')->nullable();
            $table->string('pc_name', 100)->nullable();
            $table->string('local_ip', 20)->nullable();
            $table->string('ip', 20)->nullable();
            $table->string('http_client_ip', 20)->nullable();
            $table->string('http_x_forwarded_for', 20)->nullable();
            $table->string('remote_addr', 20)->nullable();
            $table->string('browser', 255)->nullable();
            $table->json('request')->nullable();
            $table->string('id_language', 5);
            $table->timestamps();

            $table->foreign('id_ftp')->references('id_ftp')->on('ftp');
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
        Schema::dropIfExists('ftp_logs');
    }
}
