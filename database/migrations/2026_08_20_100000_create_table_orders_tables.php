<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTableOrdersTables extends Migration
{
    public function up()
    {
        Schema::create('table_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->unsignedBigInteger('shop_table_id');
            $table->string('table_label', 100)->nullable();
            $table->string('phone', 11)->nullable();
            $table->text('note')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->boolean('use_credit')->default(false);
            $table->string('payment_method', 30)->nullable();
            $table->string('receipt_path', 255)->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('purchase_id')->nullable();
            $table->timestamps();

            $table->index(['atelier_id', 'status']);
            $table->index(['shop_table_id', 'status']);
            $table->index(['phone', 'atelier_id']);
            $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
            $table->foreign('shop_table_id')->references('id')->on('shop_tables')->cascadeOnDelete();
        });

        Schema::create('table_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('table_order_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity', 12, 3);
            $table->decimal('purchase_price', 15, 2)->default(0);
            $table->decimal('sale_price', 15, 2)->default(0);
            $table->string('size', 100)->nullable();
            $table->string('color', 100)->nullable();
            $table->timestamps();

            $table->index('table_order_id');
            $table->foreign('table_order_id')->references('id')->on('table_orders')->cascadeOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('table_order_items');
        Schema::dropIfExists('table_orders');
    }
}
