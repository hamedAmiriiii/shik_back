<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddKindToShopTables extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('shop_tables', 'kind')) {
            Schema::table('shop_tables', function (Blueprint $table) {
                $table->string('kind', 16)->default('table')->after('table_number');
            });
        }

        DB::table('shop_tables')->whereNull('kind')->orWhere('kind', '')->update(['kind' => 'table']);

        Schema::table('shop_tables', function (Blueprint $table) {
            $table->dropUnique('shop_tables_unique');
        });

        Schema::table('shop_tables', function (Blueprint $table) {
            $table->unique(['atelier_id', 'kind', 'table_number'], 'shop_tables_unique');
        });
    }

    public function down()
    {
        Schema::table('shop_tables', function (Blueprint $table) {
            $table->dropUnique('shop_tables_unique');
        });

        Schema::table('shop_tables', function (Blueprint $table) {
            $table->unique(['atelier_id', 'table_number'], 'shop_tables_unique');
        });

        if (Schema::hasColumn('shop_tables', 'kind')) {
            Schema::table('shop_tables', function (Blueprint $table) {
                $table->dropColumn('kind');
            });
        }
    }
}
