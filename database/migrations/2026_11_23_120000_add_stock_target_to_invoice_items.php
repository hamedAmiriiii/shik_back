<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddStockTargetToInvoiceItems extends Migration
{
    public function up()
    {
        if (Schema::hasTable('invoice_items') && ! Schema::hasColumn('invoice_items', 'stock_target')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->string('stock_target', 16)->nullable()->after('unit_type');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('invoice_items', 'stock_target')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->dropColumn('stock_target');
            });
        }
    }
}
