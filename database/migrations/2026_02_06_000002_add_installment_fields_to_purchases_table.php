<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->enum('payment_type', ['cash', 'installment'])->default('cash')->after('credit_earned');
            $table->integer('installment_count')->nullable()->after('payment_type'); // تعداد اقساط
            $table->decimal('installment_amount', 10, 2)->nullable()->after('installment_count'); // مبلغ هر قسط
        });
    }

    public function down()
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['payment_type', 'installment_count', 'installment_amount']);
        });
    }
};

