<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBirthDateToUserShiksho extends Migration
{
    public function up()
    {
        if (Schema::hasTable('user_shiksho') && ! Schema::hasColumn('user_shiksho', 'birth_date')) {
            Schema::table('user_shiksho', function (Blueprint $table) {
                $table->date('birth_date')->nullable()->after('name');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('user_shiksho') && Schema::hasColumn('user_shiksho', 'birth_date')) {
            Schema::table('user_shiksho', function (Blueprint $table) {
                $table->dropColumn('birth_date');
            });
        }
    }
}
