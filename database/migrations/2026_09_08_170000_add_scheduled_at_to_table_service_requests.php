<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddScheduledAtToTableServiceRequests extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('table_service_requests')) {
            return;
        }

        Schema::table('table_service_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('table_service_requests', 'scheduled_at')) {
                $table->timestamp('scheduled_at')->nullable()->after('status');
            }
        });
    }

    public function down()
    {
        if (! Schema::hasTable('table_service_requests')) {
            return;
        }

        Schema::table('table_service_requests', function (Blueprint $table) {
            if (Schema::hasColumn('table_service_requests', 'scheduled_at')) {
                $table->dropColumn('scheduled_at');
            }
        });
    }
}
