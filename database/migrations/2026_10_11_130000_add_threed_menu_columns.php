<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * منوی سه‌بعدی: عکس لایه‌ای مواد اولیه، ترتیب لایه‌ها در کالای تولیدی،
 * قابل‌حذف بودن ماده در فرمول و ثبت مواد حذف‌شده در قلم سفارش میز.
 */
class AddThreedMenuColumns extends Migration
{
    public function up()
    {
        if (Schema::hasTable('raw_materials') && ! Schema::hasColumn('raw_materials', 'layer_image_path')) {
            Schema::table('raw_materials', function (Blueprint $table) {
                $table->string('layer_image_path')->nullable();
            });
        }

        if (Schema::hasTable('produced_goods') && ! Schema::hasColumn('produced_goods', 'layer_order')) {
            Schema::table('produced_goods', function (Blueprint $table) {
                $table->text('layer_order')->nullable();
            });
        }

        if (Schema::hasTable('produced_good_ingredients') && ! Schema::hasColumn('produced_good_ingredients', 'is_removable')) {
            Schema::table('produced_good_ingredients', function (Blueprint $table) {
                $table->boolean('is_removable')->default(false);
            });
        }

        if (Schema::hasTable('table_order_items') && ! Schema::hasColumn('table_order_items', 'removed_ingredients')) {
            Schema::table('table_order_items', function (Blueprint $table) {
                $table->text('removed_ingredients')->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('raw_materials', 'layer_image_path')) {
            Schema::table('raw_materials', function (Blueprint $table) {
                $table->dropColumn('layer_image_path');
            });
        }
        if (Schema::hasColumn('produced_goods', 'layer_order')) {
            Schema::table('produced_goods', function (Blueprint $table) {
                $table->dropColumn('layer_order');
            });
        }
        if (Schema::hasColumn('produced_good_ingredients', 'is_removable')) {
            Schema::table('produced_good_ingredients', function (Blueprint $table) {
                $table->dropColumn('is_removable');
            });
        }
        if (Schema::hasColumn('table_order_items', 'removed_ingredients')) {
            Schema::table('table_order_items', function (Blueprint $table) {
                $table->dropColumn('removed_ingredients');
            });
        }
    }
}
