<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdvancePayrollFeatures extends Migration
{
    public function up()
    {
        if (Schema::hasTable('shop_employees')) {
            Schema::table('shop_employees', function (Blueprint $table) {
                if (! Schema::hasColumn('shop_employees', 'commission_percent')) {
                    $table->decimal('commission_percent', 8, 2)->default(0)->after('hourly_wage');
                }
                if (! Schema::hasColumn('shop_employees', 'fixed_monthly_amount')) {
                    $table->decimal('fixed_monthly_amount', 15, 2)->default(0)->after('commission_percent');
                }
            });

            // گسترش نوع حقوق بدون شکستن enumهای قدیمی
            try {
                DB::statement("ALTER TABLE shop_employees MODIFY salary_type VARCHAR(32) NOT NULL DEFAULT 'monthly'");
            } catch (\Throwable $e) {
                // sqlite / درایورهای بدون MODIFY
            }
        }

        if (! Schema::hasTable('employee_payroll_items')) {
            Schema::create('employee_payroll_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->unsignedBigInteger('payroll_id');
                $table->string('item_type', 32); // bonus|allowance|deduction|tax|insurance
                $table->string('title', 255);
                $table->decimal('amount', 15, 2)->default(0);
                $table->boolean('is_auto')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->text('note')->nullable();
                $table->timestamps();

                $table->index(['payroll_id', 'item_type']);
                $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
                $table->foreign('payroll_id')->references('id')->on('employee_payrolls')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('employee_payroll_item_templates')) {
            Schema::create('employee_payroll_item_templates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->unsignedBigInteger('shop_employee_id');
                $table->string('item_type', 32);
                $table->string('title', 255);
                $table->decimal('amount', 15, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->text('note')->nullable();
                $table->timestamps();

                $table->index(['shop_employee_id', 'is_active']);
                $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
                $table->foreign('shop_employee_id')->references('id')->on('shop_employees')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('employee_loans')) {
            Schema::create('employee_loans', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->unsignedBigInteger('shop_employee_id');
                $table->string('title', 255)->default('وام کارمند');
                $table->decimal('principal', 15, 2);
                $table->decimal('monthly_installment', 15, 2);
                $table->decimal('remaining', 15, 2);
                $table->string('status', 20)->default('active'); // active|closed
                $table->unsignedSmallInteger('start_year')->nullable();
                $table->unsignedTinyInteger('start_month')->nullable();
                $table->text('note')->nullable();
                $table->timestamps();

                $table->index(['shop_employee_id', 'status']);
                $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
                $table->foreign('shop_employee_id')->references('id')->on('shop_employees')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('employee_attendance_days')) {
            Schema::create('employee_attendance_days', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->unsignedBigInteger('shop_employee_id');
                $table->unsignedSmallInteger('attendance_year');
                $table->unsignedTinyInteger('attendance_month');
                $table->unsignedTinyInteger('attendance_day');
                $table->string('status', 20)->default('present'); // present|absent|leave
                $table->decimal('hours', 8, 2)->nullable();
                $table->text('note')->nullable();
                $table->timestamps();

                $table->unique(
                    ['shop_employee_id', 'attendance_year', 'attendance_month', 'attendance_day'],
                    'employee_attendance_unique_day'
                );
                $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
                $table->foreign('shop_employee_id')->references('id')->on('shop_employees')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('employee_payrolls')) {
            Schema::table('employee_payrolls', function (Blueprint $table) {
                if (! Schema::hasColumn('employee_payrolls', 'base_earned_amount')) {
                    $table->decimal('base_earned_amount', 15, 2)->default(0)->after('overtime_amount');
                }
                if (! Schema::hasColumn('employee_payrolls', 'items_addition_total')) {
                    $table->decimal('items_addition_total', 15, 2)->default(0)->after('base_earned_amount');
                }
                if (! Schema::hasColumn('employee_payrolls', 'items_deduction_total')) {
                    $table->decimal('items_deduction_total', 15, 2)->default(0)->after('items_addition_total');
                }
                if (! Schema::hasColumn('employee_payrolls', 'commission_sales_amount')) {
                    $table->decimal('commission_sales_amount', 15, 2)->default(0)->after('items_deduction_total');
                }
                if (! Schema::hasColumn('employee_payrolls', 'commission_amount')) {
                    $table->decimal('commission_amount', 15, 2)->default(0)->after('commission_sales_amount');
                }
            });

            try {
                DB::statement("ALTER TABLE employee_payrolls MODIFY salary_type_snapshot VARCHAR(32) NULL");
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('employee_attendance_days');
        Schema::dropIfExists('employee_loans');
        Schema::dropIfExists('employee_payroll_item_templates');
        Schema::dropIfExists('employee_payroll_items');

        if (Schema::hasTable('employee_payrolls')) {
            Schema::table('employee_payrolls', function (Blueprint $table) {
                foreach ([
                    'base_earned_amount',
                    'items_addition_total',
                    'items_deduction_total',
                    'commission_sales_amount',
                    'commission_amount',
                ] as $col) {
                    if (Schema::hasColumn('employee_payrolls', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('shop_employees')) {
            Schema::table('shop_employees', function (Blueprint $table) {
                foreach (['commission_percent', 'fixed_monthly_amount'] as $col) {
                    if (Schema::hasColumn('shop_employees', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
}
