<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddClientIdToOilVisits extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('oil_visits') || Schema::hasColumn('oil_visits', 'client_id')) {
            return;
        }

        Schema::table('oil_visits', function (Blueprint $table) {
            $table->string('client_id', 64)->nullable()->after('created_by');
            $table->unique(['atelier_id', 'client_id'], 'oil_visits_atelier_client_id_unique');
        });
    }

    public function down()
    {
        if (! Schema::hasTable('oil_visits') || ! Schema::hasColumn('oil_visits', 'client_id')) {
            return;
        }

        Schema::table('oil_visits', function (Blueprint $table) {
            $table->dropUnique('oil_visits_atelier_client_id_unique');
            $table->dropColumn('client_id');
        });
    }
}
