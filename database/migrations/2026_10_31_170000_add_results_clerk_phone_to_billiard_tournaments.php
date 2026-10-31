<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddResultsClerkPhoneToBilliardTournaments extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('billiard_tournaments') || Schema::hasColumn('billiard_tournaments', 'results_clerk_phone')) {
            return;
        }

        Schema::table('billiard_tournaments', function (Blueprint $table) {
            $table->string('results_clerk_phone', 20)->nullable();
        });
    }

    public function down()
    {
        if (! Schema::hasTable('billiard_tournaments') || ! Schema::hasColumn('billiard_tournaments', 'results_clerk_phone')) {
            return;
        }

        Schema::table('billiard_tournaments', function (Blueprint $table) {
            $table->dropColumn('results_clerk_phone');
        });
    }
}
