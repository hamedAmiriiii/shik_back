<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddLocationToRepairRequests extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('repair_requests') || Schema::hasColumn('repair_requests', 'latitude')) {
            return;
        }

        Schema::table('repair_requests', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down()
    {
        if (Schema::hasColumn('repair_requests', 'latitude')) {
            Schema::table('repair_requests', function (Blueprint $table) {
                $table->dropColumn(['latitude', 'longitude']);
            });
        }
    }
}
