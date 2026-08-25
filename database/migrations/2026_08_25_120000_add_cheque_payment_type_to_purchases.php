<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddChequePaymentTypeToPurchases extends Migration
{
    public function up()
    {
        DB::statement("ALTER TABLE `purchases` MODIFY `payment_type` ENUM('cash', 'installment', 'debt', 'cheque') NOT NULL DEFAULT 'cash'");

        Schema::table('purchases', function (Blueprint $table) {
            if (!Schema::hasColumn('purchases', 'cheque_id')) {
                $table->unsignedBigInteger('cheque_id')->nullable()->after('payment_type');
                $table->index('cheque_id');
            }
        });

        if (Schema::hasTable('cheques')) {
            Schema::table('cheques', function (Blueprint $table) {
                if (!Schema::hasColumn('cheques', 'purchase_id')) {
                    $table->unsignedBigInteger('purchase_id')->nullable()->after('atelier_id');
                    $table->unique('purchase_id');
                }
            });

            try {
                Schema::table('purchases', function (Blueprint $table) {
                    $table->foreign('cheque_id')->references('id')->on('cheques')->nullOnDelete();
                });
            } catch (\Throwable $e) {
                // FK ممکن است از قبل باشد
            }

            try {
                Schema::table('cheques', function (Blueprint $table) {
                    $table->foreign('purchase_id')->references('id')->on('purchases')->nullOnDelete();
                });
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    public function down()
    {
        if (Schema::hasTable('cheques') && Schema::hasColumn('cheques', 'purchase_id')) {
            Schema::table('cheques', function (Blueprint $table) {
                try {
                    $table->dropForeign(['purchase_id']);
                } catch (\Throwable $e) {
                }
                $table->dropColumn('purchase_id');
            });
        }

        if (Schema::hasColumn('purchases', 'cheque_id')) {
            Schema::table('purchases', function (Blueprint $table) {
                try {
                    $table->dropForeign(['cheque_id']);
                } catch (\Throwable $e) {
                }
                $table->dropColumn('cheque_id');
            });
        }

        DB::statement("ALTER TABLE `purchases` MODIFY `payment_type` ENUM('cash', 'installment', 'debt') NOT NULL DEFAULT 'cash'");
    }
}
