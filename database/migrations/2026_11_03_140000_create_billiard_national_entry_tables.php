<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBilliardNationalEntryTables extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('billiard_national_competitions')) {
            Schema::create('billiard_national_competitions', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->unsignedBigInteger('discipline_id');
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->index(['is_active', 'sort_order']);
                $table->index('discipline_id');
            });
        }

        if (! Schema::hasTable('billiard_national_entry_requests')) {
            Schema::create('billiard_national_entry_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('player_id');
                $table->unsignedBigInteger('national_competition_id')->nullable();
                $table->string('title');
                $table->unsignedBigInteger('discipline_id');
                $table->string('note', 1000)->nullable();
                $table->string('status', 20)->default('pending');
                $table->string('admin_note', 1000)->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamps();

                $table->index(['player_id', 'status']);
                $table->index(['status', 'created_at']);
                $table->index('discipline_id');
                $table->index('national_competition_id');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('billiard_national_entry_requests');
        Schema::dropIfExists('billiard_national_competitions');
    }
}
