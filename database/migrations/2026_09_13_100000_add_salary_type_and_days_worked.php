<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shop_employees') && ! Schema::hasColumn('shop_employees', 'salary_type')) {
            Schema::table('shop_employees', function (Blueprint $table) {
                $table->string('salary_type', 20)->default('monthly')->after('is_active');
            });
        }

        if (Schema::hasTable('employee_payrolls')) {
            Schema::table('employee_payrolls', function (Blueprint $table) {
                if (! Schema::hasColumn('employee_payrolls', 'days_worked')) {
                    $table->decimal('days_worked', 8, 2)->default(0)->after('hours_worked');
                }
                if (! Schema::hasColumn('employee_payrolls', 'salary_type_snapshot')) {
                    $table->string('salary_type_snapshot', 20)->nullable()->after('days_worked');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('shop_employees') && Schema::hasColumn('shop_employees', 'salary_type')) {
            Schema::table('shop_employees', function (Blueprint $table) {
                $table->dropColumn('salary_type');
            });
        }

        if (Schema::hasTable('employee_payrolls')) {
            Schema::table('employee_payrolls', function (Blueprint $table) {
                if (Schema::hasColumn('employee_payrolls', 'salary_type_snapshot')) {
                    $table->dropColumn('salary_type_snapshot');
                }
                if (Schema::hasColumn('employee_payrolls', 'days_worked')) {
                    $table->dropColumn('days_worked');
                }
            });
        }
    }
};
