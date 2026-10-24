<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('category_stock_notify_requests')) {
            Schema::create('category_stock_notify_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->unsignedBigInteger('category_id');
                $table->string('phone', 11);
                $table->string('note', 255)->nullable();
                $table->timestamps();

                $table->unique(
                    ['atelier_id', 'category_id', 'phone'],
                    'category_stock_notify_unique'
                );
                $table->index(['atelier_id', 'category_id'], 'category_stock_notify_cat_idx');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('category_stock_notify_requests');
    }
};
