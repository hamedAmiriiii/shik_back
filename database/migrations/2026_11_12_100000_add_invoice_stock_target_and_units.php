<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddInvoiceStockTargetAndUnits extends Migration
{
    public function up()
    {
        if (Schema::hasTable('consumable_materials') && ! Schema::hasColumn('consumable_materials', 'unit_type')) {
            Schema::table('consumable_materials', function (Blueprint $table) {
                $table->string('unit_type', 16)->default('piece')->after('name');
            });
        }
        if (Schema::hasTable('invoice_items') && ! Schema::hasColumn('invoice_items', 'unit_type')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->string('unit_type', 16)->nullable()->after('quantity');
            });
        }
        if (Schema::hasTable('invoices') && ! Schema::hasColumn('invoices', 'stock_target')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->string('stock_target', 16)->nullable()->after('title');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('invoices', 'stock_target')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn('stock_target');
            });
        }
        if (Schema::hasColumn('invoice_items', 'unit_type')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->dropColumn('unit_type');
            });
        }
        if (Schema::hasColumn('consumable_materials', 'unit_type')) {
            Schema::table('consumable_materials', function (Blueprint $table) {
                $table->dropColumn('unit_type');
            });
        }
    }
}
