<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateShopTablesTable extends Migration
{
    public function up()
    {
        Schema::create('shop_tables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->unsignedSmallInteger('table_number');
            $table->string('label', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['atelier_id', 'table_number'], 'shop_tables_unique');
            $table->index(['atelier_id', 'is_active']);
            $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('shop_tables');
    }
}
