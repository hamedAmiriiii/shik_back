<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddShopSmsDeliveryStatusColumns extends Migration
{
    public function up()
    {
        Schema::table('shop_sms_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('shop_sms_logs', 'batch_id')) {
                $table->string('batch_id', 64)->nullable()->after('sms_type');
            }
            if (! Schema::hasColumn('shop_sms_logs', 'reference_id')) {
                $table->string('reference_id', 64)->nullable()->after('batch_id');
            }
            if (! Schema::hasColumn('shop_sms_logs', 'delivery_status')) {
                $table->string('delivery_status', 40)->nullable()->index()->after('reference_id');
            }
            if (! Schema::hasColumn('shop_sms_logs', 'provider_datetime')) {
                $table->string('provider_datetime', 40)->nullable()->after('delivery_status');
            }
            if (! Schema::hasColumn('shop_sms_logs', 'status_checked_at')) {
                $table->timestamp('status_checked_at')->nullable()->after('provider_datetime');
            }
        });

        try {
            Schema::table('shop_sms_logs', function (Blueprint $table) {
                $table->index('reference_id');
            });
        } catch (\Throwable $e) {
            // index may already exist
        }
    }

    public function down()
    {
        Schema::table('shop_sms_logs', function (Blueprint $table) {
            foreach (['status_checked_at', 'provider_datetime', 'delivery_status', 'reference_id', 'batch_id'] as $column) {
                if (Schema::hasColumn('shop_sms_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}
