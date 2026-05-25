<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCreditUsedToDailyShopReconciliationsTable extends Migration
{
    public function up()
    {
        Schema::table('daily_shop_reconciliations', function (Blueprint $table) {
            $table->decimal('credit_used_total', 15, 2)->default(0)->after('total_collected');
            $table->decimal('settlement_total', 15, 2)->default(0)->after('credit_used_total');
        });
    }

    public function down()
    {
        Schema::table('daily_shop_reconciliations', function (Blueprint $table) {
            $table->dropColumn(['credit_used_total', 'settlement_total']);
        });
    }
}
