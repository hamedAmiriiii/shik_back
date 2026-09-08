<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AddCreditExpiryFieldsToUserShikshoTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('user_shiksho')) {
            return;
        }

        if (! Schema::hasColumn('user_shiksho', 'credit_last_updated_at')) {
            Schema::table('user_shiksho', function (Blueprint $table) {
                $table->timestamp('credit_last_updated_at')->nullable()->after('credit');
            });
        }

        if (! Schema::hasColumn('user_shiksho', 'last_warning_sent_at')) {
            Schema::table('user_shiksho', function (Blueprint $table) {
                $table->timestamp('last_warning_sent_at')->nullable()->after('credit_last_updated_at');
            });
        }

        if (Schema::hasColumn('user_shiksho', 'credit_last_updated_at')) {
            DB::table('user_shiksho')->whereNull('credit_last_updated_at')->update([
                'credit_last_updated_at' => DB::raw('updated_at'),
            ]);
        }

        if (Schema::hasTable('settings')) {
            DB::table('settings')->insertOrIgnore([
                'key' => 'credit_expiry_days',
                'value' => '60',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down()
    {
        if (! Schema::hasTable('user_shiksho')) {
            return;
        }

        Schema::table('user_shiksho', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('user_shiksho', 'credit_last_updated_at')) {
                $cols[] = 'credit_last_updated_at';
            }
            if (Schema::hasColumn('user_shiksho', 'last_warning_sent_at')) {
                $cols[] = 'last_warning_sent_at';
            }
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
}
