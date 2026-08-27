<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMarkupPercentToProducedGoods extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('produced_goods') || Schema::hasColumn('produced_goods', 'markup_percent')) {
            return;
        }

        Schema::table('produced_goods', function (Blueprint $table) {
            $table->decimal('markup_percent', 8, 2)->nullable()->after('sale_price');
        });
    }

    public function down()
    {
        if (Schema::hasTable('produced_goods') && Schema::hasColumn('produced_goods', 'markup_percent')) {
            Schema::table('produced_goods', function (Blueprint $table) {
                $table->dropColumn('markup_percent');
            });
        }
    }
}
