<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRatingToRepairRequests extends Migration
{
    public function up()
    {
        if (Schema::hasTable('repair_requests') && ! Schema::hasColumn('repair_requests', 'rating')) {
            Schema::table('repair_requests', function (Blueprint $table) {
                $table->unsignedTinyInteger('rating')->nullable()->after('cancel_reason');
                $table->text('review')->nullable()->after('rating');
                $table->timestamp('rated_at')->nullable()->after('review');
            });
        }

        if (Schema::hasTable('repair_users') && ! Schema::hasColumn('repair_users', 'rating_avg')) {
            Schema::table('repair_users', function (Blueprint $table) {
                $table->decimal('rating_avg', 3, 2)->nullable()->after('labor_share_percent');
                $table->unsignedInteger('rating_count')->default(0)->after('rating_avg');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('repair_requests', 'rating')) {
            Schema::table('repair_requests', function (Blueprint $table) {
                $table->dropColumn(['rating', 'review', 'rated_at']);
            });
        }
        if (Schema::hasColumn('repair_users', 'rating_avg')) {
            Schema::table('repair_users', function (Blueprint $table) {
                $table->dropColumn(['rating_avg', 'rating_count']);
            });
        }
    }
}
