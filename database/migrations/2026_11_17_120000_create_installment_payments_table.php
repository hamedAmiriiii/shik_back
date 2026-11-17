<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('installment_payments')) {
            return;
        }

        Schema::create('installment_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('installment_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('notes', 1000)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index('paid_at');
            $table->index(['purchase_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_payments');
    }
};
