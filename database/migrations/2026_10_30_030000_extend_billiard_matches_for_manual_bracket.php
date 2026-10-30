<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExtendBilliardMatchesForManualBracket extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('billiard_matches')) {
            return;
        }

        Schema::table('billiard_matches', function (Blueprint $table) {
            if (! Schema::hasColumn('billiard_matches', 'phase')) {
                $table->string('phase', 20)->default('knockout')->after('tournament_id');
            }
            if (! Schema::hasColumn('billiard_matches', 'group_code')) {
                $table->string('group_code', 16)->default('')->after('phase');
            }
            if (! Schema::hasColumn('billiard_matches', 'slot1_source_match_id')) {
                $table->unsignedBigInteger('slot1_source_match_id')->nullable()->after('next_slot');
            }
            if (! Schema::hasColumn('billiard_matches', 'slot1_source_outcome')) {
                $table->string('slot1_source_outcome', 10)->nullable()->after('slot1_source_match_id');
            }
            if (! Schema::hasColumn('billiard_matches', 'slot2_source_match_id')) {
                $table->unsignedBigInteger('slot2_source_match_id')->nullable()->after('slot1_source_outcome');
            }
            if (! Schema::hasColumn('billiard_matches', 'slot2_source_outcome')) {
                $table->string('slot2_source_outcome', 10)->nullable()->after('slot2_source_match_id');
            }
        });

        $this->dropIndexQuietly('billiard_matches', 'billiard_matches_tournament_round_position_unique');
        $this->dropIndexQuietly('billiard_matches', 'billiard_matches_phase_unique');

        try {
            Schema::table('billiard_matches', function (Blueprint $table) {
                $table->unique(
                    ['tournament_id', 'phase', 'group_code', 'round', 'position'],
                    'billiard_matches_phase_unique'
                );
            });
        } catch (\Throwable $e) {
            // already exists
        }
    }

    public function down()
    {
        if (! Schema::hasTable('billiard_matches')) {
            return;
        }

        $this->dropIndexQuietly('billiard_matches', 'billiard_matches_phase_unique');

        Schema::table('billiard_matches', function (Blueprint $table) {
            foreach ([
                'slot2_source_outcome',
                'slot2_source_match_id',
                'slot1_source_outcome',
                'slot1_source_match_id',
                'group_code',
                'phase',
            ] as $col) {
                if (Schema::hasColumn('billiard_matches', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    private function dropIndexQuietly(string $tableName, string $indexName): void
    {
        try {
            DB::statement("ALTER TABLE `{$tableName}` DROP INDEX `{$indexName}`");
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
