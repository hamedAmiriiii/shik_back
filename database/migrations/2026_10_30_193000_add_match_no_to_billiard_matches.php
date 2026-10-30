<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddMatchNoToBilliardMatches extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('billiard_matches')) {
            return;
        }

        if (! Schema::hasColumn('billiard_matches', 'match_no')) {
            Schema::table('billiard_matches', function (Blueprint $table) {
                $table->unsignedInteger('match_no')->nullable()->after('tournament_id');
            });
        }

        // شماره‌گذاری پایدار برای رکوردهای موجود
        $tournamentIds = DB::table('billiard_matches')->distinct()->pluck('tournament_id');
        foreach ($tournamentIds as $tid) {
            $rows = DB::table('billiard_matches')
                ->where('tournament_id', $tid)
                ->orderBy('id')
                ->get(['id']);
            $n = 1;
            foreach ($rows as $row) {
                DB::table('billiard_matches')->where('id', $row->id)->update(['match_no' => $n]);
                $n++;
            }
        }

        try {
            Schema::table('billiard_matches', function (Blueprint $table) {
                $table->unique(['tournament_id', 'match_no'], 'billiard_matches_tournament_match_no_unique');
            });
        } catch (\Throwable $e) {
            // already exists
        }
    }

    public function down()
    {
        if (! Schema::hasTable('billiard_matches') || ! Schema::hasColumn('billiard_matches', 'match_no')) {
            return;
        }
        try {
            Schema::table('billiard_matches', function (Blueprint $table) {
                $table->dropUnique('billiard_matches_tournament_match_no_unique');
            });
        } catch (\Throwable $e) {
            try {
                DB::statement('ALTER TABLE `billiard_matches` DROP INDEX `billiard_matches_tournament_match_no_unique`');
            } catch (\Throwable $e2) {
            }
        }
        Schema::table('billiard_matches', function (Blueprint $table) {
            $table->dropColumn('match_no');
        });
    }
}
