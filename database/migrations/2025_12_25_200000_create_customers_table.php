<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCustomersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 11)->unique();
            $table->string('password');
            $table->string('name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('national_code', 10)->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
            
            $table->index('phone');
            $table->index('national_code');
            $table->index('state_id');
            $table->index('city_id');
            
            // Foreign Key constraints به صورت دستی اضافه می‌شوند (در صورت نیاز)
            // چون ممکن است type id در جداول states و cities متفاوت باشد
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('customers');
    }
}

