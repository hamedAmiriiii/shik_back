<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddGroupFieldsToBilliard extends Migration
{
    public function up()
    {
        if (Schema::hasTable('billiard_tournaments') && ! Schema::hasColumn('billiard_tournaments', 'group_count')) {
            Schema::table('billiard_tournaments', function (Blueprint $table) {
                $table->unsignedTinyInteger('group_count')->nullable()->after('format');
            });
        }

        if (Schema::hasTable('billiard_registrations') && ! Schema::hasColumn('billiard_registrations', 'group_code')) {
            Schema::table('billiard_registrations', function (Blueprint $table) {
                $table->string('group_code', 8)->nullable()->after('seed');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('billiard_tournaments') && Schema::hasColumn('billiard_tournaments', 'group_count')) {
            Schema::table('billiard_tournaments', function (Blueprint $table) {
                $table->dropColumn('group_count');
            });
        }
        if (Schema::hasTable('billiard_registrations') && Schema::hasColumn('billiard_registrations', 'group_code')) {
            Schema::table('billiard_registrations', function (Blueprint $table) {
                $table->dropColumn('group_code');
            });
        }
    }
}
