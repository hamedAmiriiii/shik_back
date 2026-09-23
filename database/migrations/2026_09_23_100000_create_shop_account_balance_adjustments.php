<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateShopAccountBalanceAdjustments extends Migration
{
    public function up()
    {
        if (Schema::hasTable('shop_account_balance_adjustments')) {
            return;
        }

        Schema::create('shop_account_balance_adjustments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->unsignedBigInteger('shop_account_id');
            $table->decimal('amount', 15, 2);
            $table->date('date');
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->index(['atelier_id', 'shop_account_id'], 'saba_atelier_account_idx');
            $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
            $table->foreign('shop_account_id')->references('id')->on('shop_accounts')->cascadeOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('shop_account_balance_adjustments');
    }
}
