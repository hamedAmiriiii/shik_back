<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEmployeePayrollPaymentsTable extends Migration
{
    public function up()
    {
        Schema::create('employee_payroll_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->unsignedBigInteger('payroll_id');
            $table->decimal('amount', 15, 2);
            $table->enum('payment_type', ['salary', 'advance', 'other'])->default('salary')
                ->comment('salary=بخشی از حقوق | advance=مساعده | other=سایر');
            $table->string('title', 255)->nullable()
                ->comment('عنوان پرداخت برای advance/other');
            $table->unsignedBigInteger('paid_by_user_id')->nullable();
            $table->unsignedBigInteger('expense_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['atelier_id', 'payroll_id']);
            $table->index(['payroll_id', 'payment_type']);
            $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
            $table->foreign('payroll_id')->references('id')->on('employee_payrolls')->cascadeOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('employee_payroll_payments');
    }
}
