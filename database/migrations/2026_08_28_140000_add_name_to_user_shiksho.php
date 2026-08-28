<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddNameToUserShiksho extends Migration
{
    public function up()
    {
        if (Schema::hasTable('user_shiksho') && ! Schema::hasColumn('user_shiksho', 'name')) {
            Schema::table('user_shiksho', function (Blueprint $table) {
                $table->string('name', 255)->nullable()->after('phone');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('user_shiksho') && Schema::hasColumn('user_shiksho', 'name')) {
            Schema::table('user_shiksho', function (Blueprint $table) {
                $table->dropColumn('name');
            });
        }
    }
}
