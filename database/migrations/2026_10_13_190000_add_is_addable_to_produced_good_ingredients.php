<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * منوی سه‌بعدی: اجازهٔ اضافه کردن یک واحد از ماده (مثل دوبل کردن برگر).
 */
class AddIsAddableToProducedGoodIngredients extends Migration
{
    public function up()
    {
        if (Schema::hasTable('produced_good_ingredients')
            && ! Schema::hasColumn('produced_good_ingredients', 'is_addable')) {
            Schema::table('produced_good_ingredients', function (Blueprint $table) {
                $table->boolean('is_addable')->default(false)->after('is_removable');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('produced_good_ingredients', 'is_addable')) {
            Schema::table('produced_good_ingredients', function (Blueprint $table) {
                $table->dropColumn('is_addable');
            });
        }
    }
}
