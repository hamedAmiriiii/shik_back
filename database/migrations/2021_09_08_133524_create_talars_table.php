<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTalarsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('talars', function (Blueprint $table) {
            $table->id();
            $table->string("name");
            $table->string("phone" , 11);
            $table->enum('status' , \App\Models\StatusEnum::STATUS )->default(\App\Models\StatusEnum::STATUS[2]);//در انتظار سررسی
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
        Schema::dropIfExists('talars');
    }
}
