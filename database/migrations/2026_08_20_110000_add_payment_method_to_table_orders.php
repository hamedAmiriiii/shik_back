<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPaymentMethodToTableOrders extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('table_orders')) {
            return;
        }
        if (! Schema::hasColumn('table_orders', 'payment_method')) {
            Schema::table('table_orders', function (Blueprint $table) {
                $table->string('payment_method', 30)->nullable()->after('use_credit');
            });
        }
    }

    public function down()
    {
        if (! Schema::hasTable('table_orders') || ! Schema::hasColumn('table_orders', 'payment_method')) {
            return;
        }
        Schema::table('table_orders', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
}
