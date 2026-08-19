<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPartialStatusToEmployeePayrollsTable extends Migration
{
    public function up()
    {
        Schema::table('employee_payrolls', function (Blueprint $table) {
            $table->decimal('base_salary_snapshot', 15, 2)->default(0)->after('salary_amount')
                ->comment('پایه حقوق کارمند در زمان ثبت');
            $table->decimal('base_work_hours_snapshot', 8, 2)->default(0)->after('base_salary_snapshot')
                ->comment('ساعت پایه در زمان ثبت');
            $table->decimal('overtime_hours', 8, 2)->default(0)->after('base_work_hours_snapshot')
                ->comment('ساعت اضافه‌کاری');
            $table->decimal('overtime_amount', 15, 2)->default(0)->after('overtime_hours')
                ->comment('مبلغ اضافه‌کاری');
        });

        // تغییر وضعیت از pending/paid به pending/partial/paid
        \Illuminate\Support\Facades\DB::statement(
            "ALTER TABLE employee_payrolls MODIFY status ENUM('pending','partial','paid') NOT NULL DEFAULT 'pending'"
        );
    }

    public function down()
    {
        Schema::table('employee_payrolls', function (Blueprint $table) {
            $table->dropColumn([
                'base_salary_snapshot',
                'base_work_hours_snapshot',
                'overtime_hours',
                'overtime_amount',
            ]);
        });

        \Illuminate\Support\Facades\DB::statement(
            "ALTER TABLE employee_payrolls MODIFY status ENUM('pending','paid') NOT NULL DEFAULT 'pending'"
        );
    }
}
