<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRoundSalePriceToProducedGoods extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('produced_goods') || Schema::hasColumn('produced_goods', 'round_sale_price')) {
            return;
        }

        Schema::table('produced_goods', function (Blueprint $table) {
            $table->boolean('round_sale_price')->default(false)->after('markup_percent');
        });
    }

    public function down()
    {
        if (Schema::hasTable('produced_goods') && Schema::hasColumn('produced_goods', 'round_sale_price')) {
            Schema::table('produced_goods', function (Blueprint $table) {
                $table->dropColumn('round_sale_price');
            });
        }
    }
}
