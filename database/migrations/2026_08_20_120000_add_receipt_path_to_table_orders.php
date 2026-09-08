<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddReceiptPathToTableOrders extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('table_orders')) {
            return;
        }
        if (! Schema::hasColumn('table_orders', 'receipt_path')) {
            Schema::table('table_orders', function (Blueprint $table) {
                $table->string('receipt_path', 255)->nullable()->after('payment_method');
            });
        }
    }

    public function down()
    {
        if (! Schema::hasTable('table_orders') || ! Schema::hasColumn('table_orders', 'receipt_path')) {
            return;
        }
        Schema::table('table_orders', function (Blueprint $table) {
            $table->dropColumn('receipt_path');
        });
    }
}
