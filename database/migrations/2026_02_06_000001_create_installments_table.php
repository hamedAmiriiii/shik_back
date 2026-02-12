<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->onDelete('cascade');
            $table->integer('installment_number'); // شماره قسط (1, 2, 3, ...)
            $table->decimal('amount', 10, 2); // مبلغ این قسط
            $table->date('due_date'); // تاریخ سررسید
            $table->boolean('is_paid')->default(false); // آیا پرداخت شده؟
            $table->dateTime('paid_at')->nullable(); // تاریخ پرداخت
            $table->text('notes')->nullable(); // یادداشت‌ها
            $table->timestamps();

            $table->index(['purchase_id', 'installment_number']);
            $table->index('due_date');
            $table->index('is_paid');
        });
    }

    public function down()
    {
        Schema::dropIfExists('installments');
    }
};

