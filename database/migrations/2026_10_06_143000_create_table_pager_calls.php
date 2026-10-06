<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTablePagerCalls extends Migration
{
    public function up()
    {
        if (Schema::hasTable('table_pager_calls')) {
            return;
        }

        Schema::create('table_pager_calls', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->unsignedBigInteger('shop_table_id');
            $table->string('status', 20)->default('pending');
            $table->string('note', 500)->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['atelier_id', 'status']);
            $table->index(['shop_table_id', 'status']);
            $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
            $table->foreign('shop_table_id')->references('id')->on('shop_tables')->cascadeOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('table_pager_calls');
    }
}
