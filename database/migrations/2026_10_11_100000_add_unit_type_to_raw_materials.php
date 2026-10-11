<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddUnitTypeToRawMaterials extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('raw_materials') || Schema::hasColumn('raw_materials', 'unit_type')) {
            return;
        }

        Schema::table('raw_materials', function (Blueprint $table) {
            $table->string('unit_type', 10)->default('kg')->after('name');
        });
    }

    public function down()
    {
        if (Schema::hasTable('raw_materials') && Schema::hasColumn('raw_materials', 'unit_type')) {
            Schema::table('raw_materials', function (Blueprint $table) {
                $table->dropColumn('unit_type');
            });
        }
    }
}
