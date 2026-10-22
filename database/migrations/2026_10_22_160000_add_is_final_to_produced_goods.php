<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIsFinalToProducedGoods extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('produced_goods') || Schema::hasColumn('produced_goods', 'is_final')) {
            return;
        }

        Schema::table('produced_goods', function (Blueprint $table) {
            // true = کالای نهایی (منو/فروش) · false = نیمه‌ساخته (فقط فرمول تولید)
            $table->boolean('is_final')->default(true)->after('unit_type');
            $table->index(['atelier_id', 'is_final'], 'produced_goods_atelier_final_idx');
        });
    }

    public function down()
    {
        if (Schema::hasTable('produced_goods') && Schema::hasColumn('produced_goods', 'is_final')) {
            Schema::table('produced_goods', function (Blueprint $table) {
                $table->dropIndex('produced_goods_atelier_final_idx');
                $table->dropColumn('is_final');
            });
        }
    }
}
