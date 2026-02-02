<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AddCreditExpiryFieldsToUserShikshoTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('user_shiksho', function (Blueprint $table) {
            $table->timestamp('credit_last_updated_at')->nullable()->after('credit');
            $table->timestamp('last_warning_sent_at')->nullable()->after('credit_last_updated_at');
        });

        // برای رکوردهای موجود، credit_last_updated_at را برابر updated_at قرار بده
        DB::table('user_shiksho')->whereNull('credit_last_updated_at')->update([
            'credit_last_updated_at' => DB::raw('updated_at')
        ]);

        // ایجاد Setting پیش‌فرض برای تعداد روز انقضا
        DB::table('settings')->insertOrIgnore([
            'key' => 'credit_expiry_days',
            'value' => '60',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('user_shiksho', function (Blueprint $table) {
            $table->dropColumn(['credit_last_updated_at', 'last_warning_sent_at']);
        });
    }
}

