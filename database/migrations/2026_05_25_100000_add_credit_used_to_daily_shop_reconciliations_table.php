<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy duplicate of add_credit_used_to_daily_shop_reconciliations.php
 * Kept idempotent for installs that already recorded this migration name.
 */
class AddCreditUsedToDailyShopReconciliationsTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('daily_shop_reconciliations')) {
            return;
        }

        if (! Schema::hasColumn('daily_shop_reconciliations', 'credit_used_total')) {
            Schema::table('daily_shop_reconciliations', function (Blueprint $table) {
                $table->decimal('credit_used_total', 15, 2)->default(0)->after('total_collected');
            });
        }

        if (! Schema::hasColumn('daily_shop_reconciliations', 'settlement_total')) {
            Schema::table('daily_shop_reconciliations', function (Blueprint $table) {
                $table->decimal('settlement_total', 15, 2)->default(0)->after('credit_used_total');
            });
        }
    }

    public function down()
    {
        // no-op
    }
}
