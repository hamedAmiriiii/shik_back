<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductPlanOrders extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('product_plan_orders')) {
            Schema::create('product_plan_orders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_plan_id');
                $table->string('product_slug', 32)->index();
                $table->string('email', 190);
                $table->string('phone', 20);
                $table->unsignedBigInteger('amount_rial');
                $table->string('description', 255)->nullable();
                $table->string('authority', 64)->nullable();
                $table->string('ref_id', 64)->nullable();
                $table->string('status', 16)->default('pending');
                $table->string('gateway', 32)->default('zarinpal');
                $table->text('return_url')->nullable();
                $table->json('meta')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'created_at']);
                $table->index('phone');
                $table->index('email');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('product_plan_orders');
    }
}
