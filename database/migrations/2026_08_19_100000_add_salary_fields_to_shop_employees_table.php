<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSalaryFieldsToShopEmployeesTable extends Migration
{
    public function up()
    {
        Schema::table('shop_employees', function (Blueprint $table) {
            $table->decimal('base_salary', 15, 2)->default(0)->after('is_active')
                ->comment('پایه حقوق ماهانه');
            $table->decimal('base_work_hours', 8, 2)->default(0)->after('base_salary')
                ->comment('ساعت کارکرد لازم برای دریافت پایه حقوق');
            $table->decimal('hourly_wage', 15, 2)->default(0)->after('base_work_hours')
                ->comment('نرخ اضافه‌کاری ساعتی');
            $table->text('note')->nullable()->after('hourly_wage');
        });
    }

    public function down()
    {
        Schema::table('shop_employees', function (Blueprint $table) {
            $table->dropColumn(['base_salary', 'base_work_hours', 'hourly_wage', 'note']);
        });
    }
}
