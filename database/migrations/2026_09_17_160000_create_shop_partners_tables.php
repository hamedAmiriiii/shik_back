<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateShopPartnersTables extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('shop_partners')) {
            Schema::create('shop_partners', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->string('name');
                $table->string('phone', 20)->nullable();
                $table->decimal('capital_amount', 15, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['atelier_id', 'is_active']);
                $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('shop_partner_settlements')) {
            Schema::create('shop_partner_settlements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->date('settled_at');
                $table->date('period_from')->nullable();
                $table->date('period_to');
                $table->decimal('net_profit', 15, 2)->default(0);
                $table->decimal('total_distributed', 15, 2)->default(0);
                $table->unsignedBigInteger('shop_account_id')->nullable();
                $table->string('user_name')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['atelier_id', 'settled_at']);
                $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('shop_partner_settlement_lines')) {
            Schema::create('shop_partner_settlement_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('settlement_id');
                $table->unsignedBigInteger('partner_id')->nullable();
                $table->string('partner_name');
                $table->decimal('capital_amount', 15, 2)->default(0);
                $table->decimal('share_percent', 8, 4)->default(0);
                $table->decimal('amount', 15, 2)->default(0);
                $table->timestamps();

                $table->index('settlement_id');
                $table->foreign('settlement_id')
                    ->references('id')
                    ->on('shop_partner_settlements')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('shop_partner_settlement_lines');
        Schema::dropIfExists('shop_partner_settlements');
        Schema::dropIfExists('shop_partners');
    }
}
