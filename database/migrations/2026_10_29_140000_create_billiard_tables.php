<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBilliardTables extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('billiard_users')) {
            Schema::create('billiard_users', function (Blueprint $table) {
                $table->id();
                $table->string('role', 20)->default('player'); // admin|operator|player
                $table->string('first_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('phone', 20)->unique();
                $table->string('national_id', 20)->nullable();
                $table->date('birth_date')->nullable();
                $table->string('photo_path')->nullable();
                $table->string('city')->nullable();
                $table->string('club')->nullable();
                $table->timestamp('membership_at')->nullable();
                $table->string('membership_status', 30)->default('active'); // active|inactive|suspended
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamp('last_login_at')->nullable();
                $table->timestamps();

                $table->unique('national_id');
                $table->index(['role', 'is_active']);
                $table->index(['last_name', 'first_name']);
            });
        }

        if (! Schema::hasTable('billiard_disciplines')) {
            Schema::create('billiard_disciplines', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('billiard_tournaments')) {
            Schema::create('billiard_tournaments', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('poster_path')->nullable();
                $table->unsignedBigInteger('discipline_id');
                $table->string('format', 40)->default('single_elimination');
                $table->string('venue')->nullable();
                $table->string('host_club')->nullable();
                $table->date('starts_on')->nullable();
                $table->date('ends_on')->nullable();
                $table->time('starts_at_time')->nullable();
                $table->unsignedInteger('capacity')->nullable();
                $table->unsignedBigInteger('fee_toman')->default(0);
                $table->timestamp('registration_deadline')->nullable();
                $table->text('description')->nullable();
                $table->text('rules')->nullable();
                $table->string('status', 40)->default('pre_registration');
                $table->timestamp('drawn_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'starts_on']);
                $table->index('discipline_id');
            });
        }

        if (! Schema::hasTable('billiard_registrations')) {
            Schema::create('billiard_registrations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tournament_id');
                $table->unsignedBigInteger('player_id');
                $table->string('status', 30)->default('pending'); // pending|confirmed|rejected|cancelled
                $table->string('payment_status', 30)->default('unpaid'); // unpaid|pending|paid|waived|failed
                $table->unsignedBigInteger('fee_toman')->default(0);
                $table->unsignedBigInteger('gateway_payment_id')->nullable();
                $table->string('payment_ref')->nullable();
                $table->unsignedInteger('seed')->nullable();
                $table->unsignedInteger('bracket_position')->nullable();
                $table->unsignedInteger('final_place')->nullable();
                $table->unsignedInteger('points_awarded')->default(0);
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();

                $table->unique(['tournament_id', 'player_id']);
                $table->index(['tournament_id', 'status']);
                $table->index(['player_id', 'status']);
            });
        }

        if (! Schema::hasTable('billiard_tables')) {
            Schema::create('billiard_tables', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tournament_id');
                $table->string('name');
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['tournament_id', 'sort_order']);
            });
        }

        if (! Schema::hasTable('billiard_matches')) {
            Schema::create('billiard_matches', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tournament_id');
                $table->unsignedInteger('match_no')->nullable();
                $table->string('phase', 20)->default('knockout'); // group|knockout
                $table->string('group_code', 16)->default('');
                $table->unsignedInteger('round')->default(1);
                $table->unsignedInteger('position')->default(1);
                $table->string('round_label')->nullable();
                $table->unsignedBigInteger('player1_id')->nullable();
                $table->unsignedBigInteger('player2_id')->nullable();
                $table->unsignedBigInteger('winner_id')->nullable();
                $table->unsignedInteger('score1')->nullable();
                $table->unsignedInteger('score2')->nullable();
                $table->unsignedBigInteger('table_id')->nullable();
                $table->date('scheduled_on')->nullable();
                $table->time('scheduled_at')->nullable();
                $table->string('status', 30)->default('scheduled'); // scheduled|live|finished|cancelled|walkover
                $table->unsignedBigInteger('next_match_id')->nullable();
                $table->unsignedTinyInteger('next_slot')->nullable(); // 1 or 2
                $table->unsignedBigInteger('slot1_source_match_id')->nullable();
                $table->string('slot1_source_outcome', 10)->nullable(); // winner|loser
                $table->unsignedBigInteger('slot2_source_match_id')->nullable();
                $table->string('slot2_source_outcome', 10)->nullable();
                $table->unsignedBigInteger('referee_id')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->unique(['tournament_id', 'phase', 'group_code', 'round', 'position'], 'billiard_matches_phase_unique');
                $table->unique(['tournament_id', 'match_no'], 'billiard_matches_tournament_match_no_unique');
                $table->index(['tournament_id', 'status']);
                $table->index(['scheduled_on', 'scheduled_at']);
            });
        }

        if (! Schema::hasTable('billiard_ranking_place_points')) {
            Schema::create('billiard_ranking_place_points', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tournament_id');
                $table->unsignedInteger('place');
                $table->unsignedInteger('points');
                $table->timestamps();

                $table->unique(['tournament_id', 'place']);
            });
        }

        if (! Schema::hasTable('billiard_ranking_settings')) {
            Schema::create('billiard_ranking_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 100)->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('billiard_ranking_entries')) {
            Schema::create('billiard_ranking_entries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('discipline_id');
                $table->unsignedBigInteger('player_id');
                $table->unsignedInteger('points')->default(0);
                $table->unsignedInteger('rank')->default(0);
                $table->unsignedInteger('tournaments_count')->default(0);
                $table->unsignedInteger('wins')->default(0);
                $table->unsignedInteger('losses')->default(0);
                $table->unsignedInteger('titles')->default(0);
                $table->unsignedInteger('runner_ups')->default(0);
                $table->unsignedInteger('thirds')->default(0);
                $table->timestamp('computed_at')->nullable();
                $table->unsignedBigInteger('source_tournament_id')->nullable();
                $table->timestamps();

                $table->unique(['discipline_id', 'player_id']);
                $table->index(['discipline_id', 'rank']);
            });
        }

        if (! Schema::hasTable('billiard_news')) {
            Schema::create('billiard_news', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('slug')->unique();
                $table->text('body');
                $table->string('cover_path')->nullable();
                $table->boolean('is_published')->default(true);
                $table->timestamp('published_at')->nullable();
                $table->timestamps();

                $table->index(['is_published', 'published_at']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('billiard_news');
        Schema::dropIfExists('billiard_ranking_entries');
        Schema::dropIfExists('billiard_ranking_settings');
        Schema::dropIfExists('billiard_ranking_place_points');
        Schema::dropIfExists('billiard_matches');
        Schema::dropIfExists('billiard_tables');
        Schema::dropIfExists('billiard_registrations');
        Schema::dropIfExists('billiard_tournaments');
        Schema::dropIfExists('billiard_disciplines');
        Schema::dropIfExists('billiard_users');
    }
}
