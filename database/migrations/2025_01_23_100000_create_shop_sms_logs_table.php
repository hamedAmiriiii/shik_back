<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateShopSmsLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('shop_sms_logs', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 11);
            $table->text('message');
            $table->string('purchase_id')->nullable(); // ID خرید مرتبط (اختیاری)
            $table->decimal('credit_amount', 15, 2)->nullable(); // مبلغ اعتبار (اگر مربوط به اعتبار باشه)
            $table->string('sms_type')->default('purchase'); // نوع پیامک: purchase, credit, warning, etc.
            $table->timestamps();
            
            // ایندکس برای جستجوی سریع
            $table->index('phone');
            $table->index('created_at');
            $table->index('sms_type');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('shop_sms_logs');
    }
}

