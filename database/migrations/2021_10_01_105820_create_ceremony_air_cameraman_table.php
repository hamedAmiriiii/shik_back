<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCeremonyAirCameramanTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ceremony_air_cameraman', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger("ceremony_id");
            $table->unsignedInteger("user_id");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ceremony_air_cameraman');
    }
}
