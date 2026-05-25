<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePurchaseItemReturnsTable extends Migration
{
    public function up()
    {
        Schema::create('purchase_item_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedBigInteger('purchased_product_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('quantity');
            $table->decimal('sale_price', 15, 2);
            $table->decimal('purchase_price', 15, 2)->default(0);
            $table->decimal('return_sale_total', 15, 2);
            $table->decimal('return_purchase_total', 15, 2)->default(0);
            $table->string('phone', 11)->nullable();
            $table->string('payment_type', 32)->nullable();
            $table->decimal('credit_used_refund', 15, 2)->default(0);
            $table->decimal('credit_earned_reversed', 15, 2)->default(0);
            $table->string('size')->nullable();
            $table->string('color')->nullable();
            $table->string('user_name')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['atelier_id', 'created_at']);
            $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
            $table->foreign('purchase_id')->references('id')->on('purchases')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('purchase_item_returns');
    }
}
