<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddShebaAndPhotoToRepairUsers extends Migration
{
    public function up()
    {
        if (Schema::hasTable('repair_users') && ! Schema::hasColumn('repair_users', 'sheba')) {
            Schema::table('repair_users', function (Blueprint $table) {
                $table->string('sheba', 34)->nullable()->after('card_number');
                $table->string('photo_path')->nullable()->after('sheba');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('repair_users', 'sheba')) {
            Schema::table('repair_users', function (Blueprint $table) {
                $table->dropColumn(['sheba', 'photo_path']);
            });
        }
    }
}
