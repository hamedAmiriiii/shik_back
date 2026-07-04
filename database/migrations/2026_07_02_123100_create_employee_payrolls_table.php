<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEmployeePayrollsTable extends Migration
{
    public function up()
    {
        Schema::create('employee_payrolls', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->unsignedBigInteger('shop_employee_id');
            $table->unsignedSmallInteger('payroll_year');
            $table->unsignedTinyInteger('payroll_month');
            $table->decimal('hours_worked', 10, 2)->default(0);
            $table->decimal('hourly_wage', 15, 2)->default(0);
            $table->decimal('salary_amount', 15, 2)->default(0);
            $table->enum('status', ['pending', 'paid'])->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('paid_by_user_id')->nullable();
            $table->unsignedBigInteger('expense_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['shop_employee_id', 'payroll_year', 'payroll_month'], 'employee_payroll_unique_month');
            $table->index(['atelier_id', 'payroll_year', 'payroll_month']);
            $table->index(['atelier_id', 'status']);
            $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
            $table->foreign('shop_employee_id')->references('id')->on('shop_employees')->cascadeOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('employee_payrolls');
    }
}
