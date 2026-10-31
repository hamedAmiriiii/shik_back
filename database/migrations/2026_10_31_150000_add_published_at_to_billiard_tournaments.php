<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddPublishedAtToBilliardTournaments extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('billiard_tournaments') || Schema::hasColumn('billiard_tournaments', 'published_at')) {
            return;
        }

        Schema::table('billiard_tournaments', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('drawn_at');
        });

        // مسابقه‌های قبلی همین حالا برای عموم باز بمانند. مسابقهٔ جدید تا دکمهٔ انتشار مخفی است.
        DB::table('billiard_tournaments')->update(['published_at' => now()]);
    }

    public function down()
    {
        if (! Schema::hasTable('billiard_tournaments') || ! Schema::hasColumn('billiard_tournaments', 'published_at')) {
            return;
        }

        Schema::table('billiard_tournaments', function (Blueprint $table) {
            $table->dropColumn('published_at');
        });
    }
}
