<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateShopPlansAndGatewayPayments extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('shop_plans')) {
            Schema::create('shop_plans', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->unsignedInteger('duration_days');
                $table->unsignedBigInteger('price_rial');
                $table->boolean('is_active')->default(true);
                $table->unsignedTinyInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('gateway_payments')) {
            Schema::create('gateway_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('type', 32);
                $table->unsignedBigInteger('item_id');
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

                $table->unique('authority');
                $table->index(['atelier_id', 'status']);
                $table->index(['type', 'item_id']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('gateway_payments');
        Schema::dropIfExists('shop_plans');
    }
}
