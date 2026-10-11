<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateShopContractAcceptancesTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('shop_contract_acceptances')) {
            return;
        }

        Schema::create('shop_contract_acceptances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('atelier_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('shop_name')->nullable();
            $table->string('contract_version', 20);
            // sms_otp = تأیید با کد پیامکی | legacy_backfill = مشتریان قبل از اجباری شدن قرارداد
            $table->string('method', 32)->default('sms_otp');
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['atelier_id', 'contract_version'], 'shop_contract_acceptances_atelier_version_unique');
            $table->index('user_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('shop_contract_acceptances');
    }
}
