<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('last_name');
            $table->string('national_code', 10)->unique();
            $table->string('phone', 11)->unique();
            $table->enum('gender', ["مرد", "زن"])->nullable();
            //$table->enum('status' , \App\Models\StatusEnum::STATUS )->default(\App\Models\StatusEnum::STATUS[0]);//در انتظار سررسی
            $table->string('password');
            $table->rememberToken();
            $table->integer('atelier_id')->nullable();
            $table->string('national_cart');
            $table->string('birth_certificate')->nullable();
            $table->string('personality_image')->nullable();
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
        Schema::dropIfExists('users');
    }
}
