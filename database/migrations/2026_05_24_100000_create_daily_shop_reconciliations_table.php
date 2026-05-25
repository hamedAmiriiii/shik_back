<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDailyShopReconciliationsTable extends Migration
{
    public function up()
    {
        Schema::create('daily_shop_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->date('date');

            $table->decimal('total_sales', 15, 2)->default(0);
            $table->decimal('card_amount', 15, 2)->default(0);
            $table->decimal('cash_amount', 15, 2)->default(0);
            $table->decimal('installments_collected', 15, 2)->default(0);
            $table->decimal('total_collected', 15, 2)->default(0);
            $table->decimal('credit_used_total', 15, 2)->default(0);
            $table->decimal('settlement_total', 15, 2)->default(0);

            $table->decimal('deposit_account_1', 15, 2)->default(0);
            $table->decimal('deposit_account_2', 15, 2)->default(0);
            $table->decimal('deposit_cash', 15, 2)->default(0);
            $table->decimal('deposited_total', 15, 2)->default(0);
            $table->decimal('daily_discrepancy', 15, 2)->default(0);

            $table->text('notes')->nullable();
            $table->string('user_name')->nullable();

            $table->unsignedBigInteger('invoice_account_1_id')->nullable();
            $table->unsignedBigInteger('invoice_account_2_id')->nullable();
            $table->unsignedBigInteger('invoice_cash_id')->nullable();

            $table->timestamps();

            $table->unique(['atelier_id', 'date']);
            $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
            $table->foreign('invoice_account_1_id')->references('id')->on('invoices')->nullOnDelete();
            $table->foreign('invoice_account_2_id')->references('id')->on('invoices')->nullOnDelete();
            $table->foreign('invoice_cash_id')->references('id')->on('invoices')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('daily_shop_reconciliations');
    }
}
