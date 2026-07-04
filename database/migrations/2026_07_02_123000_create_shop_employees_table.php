<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateShopEmployeesTable extends Migration
{
    public function up()
    {
        Schema::create('shop_employees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->string('name', 255);
            $table->string('phone', 11)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['atelier_id', 'is_active']);
            $table->index(['atelier_id', 'phone']);
            $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('shop_employees');
    }
}
