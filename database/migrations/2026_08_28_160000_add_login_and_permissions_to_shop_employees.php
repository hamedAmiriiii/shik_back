<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddLoginAndPermissionsToShopEmployees extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('shop_employees')) {
            return;
        }

        Schema::table('shop_employees', function (Blueprint $table) {
            if (! Schema::hasColumn('shop_employees', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('atelier_id');
                $table->unique('user_id');
            }
            if (! Schema::hasColumn('shop_employees', 'permissions')) {
                $table->json('permissions')->nullable()->after('note');
            }
        });
    }

    public function down()
    {
        if (! Schema::hasTable('shop_employees')) {
            return;
        }

        Schema::table('shop_employees', function (Blueprint $table) {
            if (Schema::hasColumn('shop_employees', 'user_id')) {
                $table->dropUnique(['user_id']);
                $table->dropColumn('user_id');
            }
            if (Schema::hasColumn('shop_employees', 'permissions')) {
                $table->dropColumn('permissions');
            }
        });
    }
}
