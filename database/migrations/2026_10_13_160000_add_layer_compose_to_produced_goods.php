<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حالت ترکیب لایه‌های منوی سه‌بعدی:
 * side = ساندویچ نمای کنار | overlay = پیتزا نمای بالا (روی‌هم‌افتادگی کامل)
 */
class AddLayerComposeToProducedGoods extends Migration
{
    public function up()
    {
        if (Schema::hasTable('produced_goods') && ! Schema::hasColumn('produced_goods', 'layer_compose')) {
            Schema::table('produced_goods', function (Blueprint $table) {
                $table->string('layer_compose', 16)->default('side')->after('layer_order');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('produced_goods', 'layer_compose')) {
            Schema::table('produced_goods', function (Blueprint $table) {
                $table->dropColumn('layer_compose');
            });
        }
    }
}
