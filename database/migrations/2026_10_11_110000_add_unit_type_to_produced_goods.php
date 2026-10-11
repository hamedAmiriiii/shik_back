<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddUnitTypeToProducedGoods extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('produced_goods') || Schema::hasColumn('produced_goods', 'unit_type')) {
            return;
        }

        Schema::table('produced_goods', function (Blueprint $table) {
            $table->string('unit_type', 10)->default('kg')->after('name');
        });
    }

    public function down()
    {
        if (Schema::hasTable('produced_goods') && Schema::hasColumn('produced_goods', 'unit_type')) {
            Schema::table('produced_goods', function (Blueprint $table) {
                $table->dropColumn('unit_type');
            });
        }
    }
}
