<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateShopPartnerActivityLogsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('shop_partner_activity_logs')) {
            return;
        }

        Schema::create('shop_partner_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name', 255)->nullable();
            $table->string('action', 40);
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->unsignedBigInteger('settlement_id')->nullable();
            $table->string('message', 1000)->nullable();
            $table->text('payload')->nullable();
            $table->timestamps();

            $table->index(['atelier_id', 'created_at']);
            $table->index('partner_id');
            $table->index('settlement_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('shop_partner_activity_logs');
    }
}
