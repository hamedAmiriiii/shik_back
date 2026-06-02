<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDiscountGivenToDailyShopReconciliationsTable extends Migration
{
    public function up()
    {
        Schema::table('daily_shop_reconciliations', function (Blueprint $table) {
            $table->decimal('discount_given', 15, 2)->default(0)->after('settlement_total');
        });
    }

    public function down()
    {
        Schema::table('daily_shop_reconciliations', function (Blueprint $table) {
            $table->dropColumn('discount_given');
        });
    }
}
