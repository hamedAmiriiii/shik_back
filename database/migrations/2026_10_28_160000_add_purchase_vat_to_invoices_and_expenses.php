<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPurchaseVatToInvoicesAndExpenses extends Migration
{
    public function up()
    {
        foreach (['invoices', 'expenses'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'vat_mode')) {
                    $blueprint->string('vat_mode', 16)->default('none')->after('amount');
                }
                if (! Schema::hasColumn($table, 'vat_percent')) {
                    $blueprint->decimal('vat_percent', 5, 2)->nullable()->after('vat_mode');
                }
                if (! Schema::hasColumn($table, 'vat_amount')) {
                    $blueprint->decimal('vat_amount', 15, 2)->default(0)->after('vat_percent');
                }
            });
        }
    }

    public function down()
    {
        foreach (['invoices', 'expenses'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $drops = [];
                foreach (['vat_amount', 'vat_percent', 'vat_mode'] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $drops[] = $column;
                    }
                }
                if ($drops !== []) {
                    $blueprint->dropColumn($drops);
                }
            });
        }
    }
}
