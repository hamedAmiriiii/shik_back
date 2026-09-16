<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductStockNotifyRequestsTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('product_stock_notify_requests')) {
            Schema::create('product_stock_notify_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->unsignedBigInteger('product_id');
                $table->string('phone', 11);
                $table->timestamps();

                $table->unique(
                    ['atelier_id', 'product_id', 'phone'],
                    'product_stock_notify_unique'
                );
                $table->index(['atelier_id', 'product_id'], 'product_stock_notify_product_idx');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('product_stock_notify_requests');
    }
}
