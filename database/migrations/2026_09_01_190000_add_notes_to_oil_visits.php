<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddNotesToOilVisits extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('oil_visits') || Schema::hasColumn('oil_visits', 'notes')) {
            return;
        }

        Schema::table('oil_visits', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('next_km');
        });
    }

    public function down()
    {
        if (! Schema::hasTable('oil_visits') || ! Schema::hasColumn('oil_visits', 'notes')) {
            return;
        }

        Schema::table('oil_visits', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
}
