<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCeremoniesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ceremonies', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger("talar_id");
            $table->unsignedInteger("garden_id");
            $table->unsignedInteger("atelier_id");
            $table->string("groom_full_name");
            $table->string("groom_phone");
            $table->string("groom_national_code");
            $table->timestamp("date");
            $table->enum("status" , \App\Models\StatusEnum::STATUS)->default(\App\Models\StatusEnum::STATUS[0]);
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
        Schema::dropIfExists('ceremonies');
    }
}
