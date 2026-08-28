<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddDeferredPaymentToExpensesAndInvoices extends Migration
{
    public function up()
    {
        foreach (['expenses', 'invoices'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'payment_method')) {
                    $table->string('payment_method', 16)->default('account')->after('shop_account_id');
                }
                if (! Schema::hasColumn($tableName, 'payment_status')) {
                    $table->string('payment_status', 16)->default('paid')->after('payment_method');
                }
                if (! Schema::hasColumn($tableName, 'paid_at')) {
                    $table->timestamp('paid_at')->nullable()->after('payment_status');
                }
                if (! Schema::hasColumn($tableName, 'cheque_id')) {
                    $table->unsignedBigInteger('cheque_id')->nullable()->after('paid_at');
                }
            });
        }

        if (Schema::hasTable('cheques')) {
            Schema::table('cheques', function (Blueprint $table) {
                if (! Schema::hasColumn('cheques', 'invoice_id')) {
                    $table->unsignedBigInteger('invoice_id')->nullable()->after('expense_id');
                }
                if (! Schema::hasColumn('cheques', 'shop_account_id')) {
                    $table->unsignedBigInteger('shop_account_id')->nullable()->after('invoice_id');
                }
            });
        }

        if (Schema::hasTable('expenses') && Schema::hasColumn('expenses', 'shop_account_id')) {
            DB::table('expenses')->whereNotNull('shop_account_id')->update([
                'payment_method' => 'account',
                'payment_status' => 'paid',
            ]);
        }
        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'shop_account_id')) {
            DB::table('invoices')->whereNotNull('shop_account_id')->update([
                'payment_method' => 'account',
                'payment_status' => 'paid',
            ]);
        }
    }

    public function down()
    {
        foreach (['expenses', 'invoices'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                foreach (['cheque_id', 'paid_at', 'payment_status', 'payment_method'] as $col) {
                    if (Schema::hasColumn($tableName, $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('cheques')) {
            Schema::table('cheques', function (Blueprint $table) {
                if (Schema::hasColumn('cheques', 'shop_account_id')) {
                    $table->dropColumn('shop_account_id');
                }
                if (Schema::hasColumn('cheques', 'invoice_id')) {
                    $table->dropColumn('invoice_id');
                }
            });
        }
    }
}
