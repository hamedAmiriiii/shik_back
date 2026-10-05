<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddOnlinePaymentToTableOrders extends Migration
{
    public function up()
    {
        Schema::table('table_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('table_orders', 'gateway_payment_id')) {
                $table->unsignedBigInteger('gateway_payment_id')->nullable()->after('purchase_id');
            }
            if (! Schema::hasColumn('table_orders', 'online_paid_at')) {
                $table->timestamp('online_paid_at')->nullable()->after('gateway_payment_id');
            }
            if (! Schema::hasColumn('table_orders', 'online_ref_id')) {
                $table->string('online_ref_id', 64)->nullable()->after('online_paid_at');
            }
            if (! Schema::hasColumn('table_orders', 'cancelled_by')) {
                $table->string('cancelled_by', 20)->nullable()->after('online_ref_id');
            }
            if (! Schema::hasColumn('table_orders', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            }
        });

        DB::statement('ALTER TABLE `table_order_items` MODIFY `product_id` BIGINT UNSIGNED NULL');

        Schema::table('table_order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('table_order_items', 'produced_good_id')) {
                $table->unsignedBigInteger('produced_good_id')->nullable()->after('product_id');
            }
            if (! Schema::hasColumn('table_order_items', 'raw_material_id')) {
                $table->unsignedBigInteger('raw_material_id')->nullable()->after('produced_good_id');
            }
            if (! Schema::hasColumn('table_order_items', 'item_name')) {
                $table->string('item_name', 255)->nullable()->after('raw_material_id');
            }
        });
    }

    public function down()
    {
        Schema::table('table_order_items', function (Blueprint $table) {
            foreach (['item_name', 'raw_material_id', 'produced_good_id'] as $col) {
                if (Schema::hasColumn('table_order_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('table_orders', function (Blueprint $table) {
            foreach (['cancelled_at', 'cancelled_by', 'online_ref_id', 'online_paid_at', 'gateway_payment_id'] as $col) {
                if (Schema::hasColumn('table_orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
}
