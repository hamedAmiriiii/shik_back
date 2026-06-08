<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateDailyShopReconciliationDepositsTable extends Migration
{
    public function up()
    {
        Schema::create('daily_shop_reconciliation_deposits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->decimal('amount', 15, 2);
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('date');
            $table->string('user_name')->nullable();
            $table->timestamps();

            $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
        });

        if (Schema::hasTable('daily_shop_reconciliations')) {
            Schema::table('daily_shop_reconciliations', function (Blueprint $table) {
                $table->dropForeign(['invoice_account_1_id']);
                $table->dropForeign(['invoice_account_2_id']);
                $table->dropForeign(['invoice_cash_id']);
            });

            DB::statement('ALTER TABLE `daily_shop_reconciliations`
                CHANGE COLUMN `invoice_account_1_id` `deposit_record_account_1_id` BIGINT UNSIGNED NULL,
                CHANGE COLUMN `invoice_account_2_id` `deposit_record_account_2_id` BIGINT UNSIGNED NULL,
                CHANGE COLUMN `invoice_cash_id` `deposit_record_cash_id` BIGINT UNSIGNED NULL');

            Schema::table('daily_shop_reconciliations', function (Blueprint $table) {
                $table->foreign('deposit_record_account_1_id')
                    ->references('id')
                    ->on('daily_shop_reconciliation_deposits')
                    ->nullOnDelete();
                $table->foreign('deposit_record_account_2_id')
                    ->references('id')
                    ->on('daily_shop_reconciliation_deposits')
                    ->nullOnDelete();
                $table->foreign('deposit_record_cash_id')
                    ->references('id')
                    ->on('daily_shop_reconciliation_deposits')
                    ->nullOnDelete();
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('daily_shop_reconciliations')) {
            Schema::table('daily_shop_reconciliations', function (Blueprint $table) {
                $table->dropForeign(['deposit_record_account_1_id']);
                $table->dropForeign(['deposit_record_account_2_id']);
                $table->dropForeign(['deposit_record_cash_id']);
            });

            DB::statement('ALTER TABLE `daily_shop_reconciliations`
                CHANGE COLUMN `deposit_record_account_1_id` `invoice_account_1_id` BIGINT UNSIGNED NULL,
                CHANGE COLUMN `deposit_record_account_2_id` `invoice_account_2_id` BIGINT UNSIGNED NULL,
                CHANGE COLUMN `deposit_record_cash_id` `invoice_cash_id` BIGINT UNSIGNED NULL');

            Schema::table('daily_shop_reconciliations', function (Blueprint $table) {
                $table->foreign('invoice_account_1_id')->references('id')->on('invoices')->nullOnDelete();
                $table->foreign('invoice_account_2_id')->references('id')->on('invoices')->nullOnDelete();
                $table->foreign('invoice_cash_id')->references('id')->on('invoices')->nullOnDelete();
            });
        }

        Schema::dropIfExists('daily_shop_reconciliation_deposits');
    }
}
