<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDisplayOrderToProductsTable extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('products', 'display_order')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unsignedSmallInteger('display_order')->default(50)->after('description');
                $table->index(['atelier_id', 'display_order'], 'products_atelier_display_order_index');
            });
        }

        if (Schema::hasTable('produced_goods') && ! Schema::hasColumn('produced_goods', 'display_order')) {
            Schema::table('produced_goods', function (Blueprint $table) {
                $table->unsignedSmallInteger('display_order')->default(50)->after('name');
                $table->index(['atelier_id', 'display_order'], 'produced_goods_atelier_display_order_index');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('products', 'display_order')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex('products_atelier_display_order_index');
                $table->dropColumn('display_order');
            });
        }

        if (Schema::hasTable('produced_goods') && Schema::hasColumn('produced_goods', 'display_order')) {
            Schema::table('produced_goods', function (Blueprint $table) {
                $table->dropIndex('produced_goods_atelier_display_order_index');
                $table->dropColumn('display_order');
            });
        }
    }
}
