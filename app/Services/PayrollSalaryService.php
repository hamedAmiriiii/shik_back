<?php

namespace App\Services;

use App\Models\EmployeeAttendanceDay;
use App\Models\EmployeeLoan;
use App\Models\EmployeePayroll;
use App\Models\EmployeePayrollItem;
use App\Models\EmployeePayrollItemTemplate;
use App\Models\Purchase;
use App\Models\Setting;
use App\Models\ShopEmployee;
use Illuminate\Support\Facades\Schema;
use Morilog\Jalali\Jalalian;

class PayrollSalaryService
{
    /**
     * @return array{
     *   salary_amount: float,
     *   base_earned_amount: float,
     *   base_salary_snapshot: float,
     *   base_work_hours_snapshot: float,
     *   overtime_hours: float,
     *   overtime_amount: float,
     *   days_worked: float,
     *   salary_type: string,
     *   commission_sales_amount: float,
     *   commission_amount: float,
     *   hours_worked: float
     * }
     */
    public function calculateBase(
        ShopEmployee $employee,
        float $hoursWorked = 0,
        float $daysWorked = 0,
        float $overtimeHours = 0,
        ?int $year = null,
        ?int $month = null
    ): array {
        $type = (string) ($employee->salary_type ?: ShopEmployee::SALARY_TYPE_MONTHLY);

        return match ($type) {
            ShopEmployee::SALARY_TYPE_DAILY => $this->withCommissionDefaults(
                $employee->calculateDailySalaryPublic($daysWorked, $overtimeHours),
                $hoursWorked
            ),
            ShopEmployee::SALARY_TYPE_HOURLY => $this->calculateHourly($employee, $hoursWorked, $overtimeHours),
            ShopEmployee::SALARY_TYPE_COMMISSION => $this->calculateCommission($employee, $year, $month, false),
            ShopEmployee::SALARY_TYPE_MIXED => $this->calculateCommission($employee, $year, $month, true, $hoursWorked),
            ShopEmployee::SALARY_TYPE_FIXED => $this->calculateFixed($employee),
            default => $this->withCommissionDefaults(
                $employee->calculateMonthlySalaryPublic($hoursWorked),
                $hoursWorked
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $calc
     * @return array<string, mixed>
     */
    protected function withCommissionDefaults(array $calc, float $hoursWorked = 0): array
    {
        $calc['commission_sales_amount'] = (float) ($calc['commission_sales_amount'] ?? 0);
        $calc['commission_amount'] = (float) ($calc['commission_amount'] ?? 0);
        $calc['base_earned_amount'] = (float) ($calc['base_earned_amount'] ?? $calc['salary_amount']);
        $calc['hours_worked'] = (float) ($calc['hours_worked'] ?? $hoursWorked);

        return $calc;
    }

    /**
     * @return array<string, mixed>
     */
    protected function calculateHourly(ShopEmployee $employee, float $hoursWorked, float $overtimeHours): array
    {
        $rate = (float) $employee->hourly_wage;
        $hours = max(0, $hoursWorked);
        $ot = max(0, $overtimeHours);
        // اگر اضافه‌کار جدا نیامده و فقط کل ساعات آمده، همه با نرخ ساعتی
        $regularPay = round($rate * $hours, 2);
        $otPay = round($rate * $ot, 2);
        $total = round($regularPay + $otPay, 2);

        return [
            'salary_amount' => $total,
            'base_earned_amount' => $total,
            'base_salary_snapshot' => 0,
            'base_work_hours_snapshot' => 0,
            'overtime_hours' => $ot,
            'overtime_amount' => $otPay,
            'days_worked' => 0,
            'salary_type' => ShopEmployee::SALARY_TYPE_HOURLY,
            'commission_sales_amount' => 0,
            'commission_amount' => 0,
            'hours_worked' => $hours,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function calculateFixed(ShopEmployee $employee): array
    {
        $amount = (float) ($employee->fixed_monthly_amount ?: $employee->base_salary);

        return [
            'salary_amount' => round($amount, 2),
            'base_earned_amount' => round($amount, 2),
            'base_salary_snapshot' => round($amount, 2),
            'base_work_hours_snapshot' => 0,
            'overtime_hours' => 0,
            'overtime_amount' => 0,
            'days_worked' => 0,
            'salary_type' => ShopEmployee::SALARY_TYPE_FIXED,
            'commission_sales_amount' => 0,
            'commission_amount' => 0,
            'hours_worked' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function calculateCommission(
        ShopEmployee $employee,
        ?int $year,
        ?int $month,
        bool $withBase,
        float $hoursWorked = 0
    ): array {
        $sales = 0.0;
        if ($year && $month) {
            $sales = $this->employeeSalesAmount($employee, $year, $month);
        }
        $percent = (float) ($employee->commission_percent ?? 0);
        $commission = round($sales * ($percent / 100), 2);

        if ($withBase) {
            $monthly = $employee->calculateMonthlySalaryPublic($hoursWorked);
            $baseEarned = (float) $monthly['salary_amount'];
            $total = round($baseEarned + $commission, 2);

            return [
                'salary_amount' => $total,
                'base_earned_amount' => $total,
                'base_salary_snapshot' => (float) $monthly['base_salary_snapshot'],
                'base_work_hours_snapshot' => (float) $monthly['base_work_hours_snapshot'],
                'overtime_hours' => (float) $monthly['overtime_hours'],
                'overtime_amount' => (float) $monthly['overtime_amount'],
                'days_worked' => 0,
                'salary_type' => ShopEmployee::SALARY_TYPE_MIXED,
                'commission_sales_amount' => $sales,
                'commission_amount' => $commission,
                'hours_worked' => $hoursWorked,
            ];
        }

        // فقط پورسانت + حداقل پایه اختیاری (base_salary به‌عنوان کف)
        $floor = (float) $employee->base_salary;
        $earned = max($commission, $floor);
        $type = ShopEmployee::SALARY_TYPE_COMMISSION;

        return [
            'salary_amount' => round($earned, 2),
            'base_earned_amount' => round($earned, 2),
            'base_salary_snapshot' => $floor,
            'base_work_hours_snapshot' => 0,
            'overtime_hours' => 0,
            'overtime_amount' => 0,
            'days_worked' => 0,
            'salary_type' => $type,
            'commission_sales_amount' => $sales,
            'commission_amount' => $commission,
            'hours_worked' => 0,
        ];
    }

    public function employeeSalesAmount(ShopEmployee $employee, int $year, int $month): float
    {
        if (! $employee->user_id || ! Schema::hasTable('purchases')) {
            return 0.0;
        }

        try {
            $start = (new Jalalian($year, $month, 1))->toCarbon()->startOfDay();
            $end = (new Jalalian($year, $month, 1))->addMonths(1)->toCarbon()->startOfDay();
        } catch (\Throwable $e) {
            return 0.0;
        }

        $query = Purchase::query()
            ->where('sold_by_user_id', (int) $employee->user_id)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end);

        if (Schema::hasColumn('purchases', 'atelier_id')) {
            $query->where(function ($q) use ($employee) {
                $q->where('atelier_id', $employee->atelier_id)
                    ->orWhereNull('atelier_id');
            });
        }

        return round((float) $query->sum('total_amount'), 2);
    }

    /**
     * اعمال مجموع ردیف‌ها روی مبلغ پایه و به‌روزرسانی فیش.
     */
    public function recomputePayrollTotals(EmployeePayroll $payroll): EmployeePayroll
    {
        $baseEarned = Schema::hasColumn('employee_payrolls', 'base_earned_amount')
            ? (float) $payroll->base_earned_amount
            : ((float) $payroll->salary_amount
                - (float) ($payroll->items_addition_total ?? 0)
                + (float) ($payroll->items_deduction_total ?? 0));

        if ($baseEarned <= 0 && (float) $payroll->salary_amount > 0 && ! $payroll->items()->exists()) {
            $baseEarned = (float) $payroll->salary_amount;
        }

        $items = $payroll->items()->get();
        $addition = 0.0;
        $deduction = 0.0;
        foreach ($items as $item) {
            $amount = abs((float) $item->amount);
            if ($item->isAddition()) {
                $addition += $amount;
            } else {
                $deduction += $amount;
            }
        }

        $net = round(max(0, $baseEarned + $addition - $deduction), 2);

        $payload = ['salary_amount' => $net];
        if (Schema::hasColumn('employee_payrolls', 'base_earned_amount')) {
            $payload['base_earned_amount'] = round($baseEarned, 2);
        }
        if (Schema::hasColumn('employee_payrolls', 'items_addition_total')) {
            $payload['items_addition_total'] = round($addition, 2);
        }
        if (Schema::hasColumn('employee_payrolls', 'items_deduction_total')) {
            $payload['items_deduction_total'] = round($deduction, 2);
        }

        $payroll->update($payload);
        $payroll->syncStatus();

        return $payroll->fresh(['items', 'employee', 'payments']);
    }

    /**
     * افزودن قالب‌های تکرارشونده و اقساط وام (اگر هنوز برای این فیش اضافه نشده‌اند).
     */
    public function applyRecurringAndLoans(EmployeePayroll $payroll, ShopEmployee $employee): void
    {
        if (! Schema::hasTable('employee_payroll_items')) {
            return;
        }

        $existingTitles = $payroll->items()->pluck('title')->map(fn ($t) => mb_strtolower(trim((string) $t)))->all();

        if (Schema::hasTable('employee_payroll_item_templates')) {
            $templates = EmployeePayrollItemTemplate::query()
                ->where('shop_employee_id', $employee->id)
                ->where('is_active', true)
                ->get();

            foreach ($templates as $tpl) {
                $key = mb_strtolower(trim((string) $tpl->title));
                if (in_array($key, $existingTitles, true)) {
                    continue;
                }
                $payroll->items()->create([
                    'atelier_id' => $payroll->atelier_id,
                    'item_type' => $tpl->item_type,
                    'title' => $tpl->title,
                    'amount' => abs((float) $tpl->amount),
                    'is_auto' => true,
                    'note' => $tpl->note,
                ]);
                $existingTitles[] = $key;
            }
        }

        if (Schema::hasTable('employee_loans')) {
            $loans = EmployeeLoan::query()
                ->where('shop_employee_id', $employee->id)
                ->where('status', EmployeeLoan::STATUS_ACTIVE)
                ->where('remaining', '>', 0)
                ->get();

            foreach ($loans as $loan) {
                $installment = $loan->installmentForMonth();
                if ($installment <= 0) {
                    continue;
                }
                $title = 'قسط وام: '.$loan->title;
                $key = mb_strtolower(trim($title));
                if (in_array($key, $existingTitles, true)) {
                    continue;
                }
                $payroll->items()->create([
                    'atelier_id' => $payroll->atelier_id,
                    'item_type' => EmployeePayrollItem::TYPE_DEDUCTION,
                    'title' => $title,
                    'amount' => $installment,
                    'is_auto' => true,
                    'note' => 'loan:'.$loan->id,
                ]);
                $loan->applyInstallment($installment);
                $existingTitles[] = $key;
            }
        }
    }

    /**
     * محاسبه خودکار مالیات/بیمه روی ناخالص (پایه + اضافات بدون کسورات).
     */
    public function applyAutoTaxInsurance(EmployeePayroll $payroll, bool $replaceExisting = true): void
    {
        if (! Schema::hasTable('employee_payroll_items')) {
            return;
        }

        Setting::setContextAtelierId((int) $payroll->atelier_id);
        $taxPercent = (float) Setting::get('payroll_tax_percent', '0');
        $insurancePercent = (float) Setting::get('payroll_insurance_employee_percent', '0');
        $taxFixed = (float) Setting::get('payroll_tax_fixed', '0');
        $insuranceFixed = (float) Setting::get('payroll_insurance_fixed', '0');

        if ($taxPercent <= 0 && $insurancePercent <= 0 && $taxFixed <= 0 && $insuranceFixed <= 0) {
            return;
        }

        $baseEarned = Schema::hasColumn('employee_payrolls', 'base_earned_amount')
            ? (float) $payroll->base_earned_amount
            : (float) $payroll->salary_amount;

        $additions = (float) $payroll->items()
            ->whereIn('item_type', EmployeePayrollItem::ADDITION_TYPES)
            ->sum('amount');

        $gross = max(0, $baseEarned + $additions);

        if ($replaceExisting) {
            $payroll->items()
                ->where('is_auto', true)
                ->whereIn('item_type', [EmployeePayrollItem::TYPE_TAX, EmployeePayrollItem::TYPE_INSURANCE])
                ->where(function ($q) {
                    $q->whereNull('note')->orWhere('note', 'not like', 'loan:%');
                })
                ->whereIn('title', ['مالیات حقوق', 'بیمه سهم کارمند'])
                ->delete();
        }

        $taxAmount = round(($gross * $taxPercent / 100) + $taxFixed, 2);
        $insAmount = round(($gross * $insurancePercent / 100) + $insuranceFixed, 2);

        if ($taxAmount > 0) {
            $payroll->items()->create([
                'atelier_id' => $payroll->atelier_id,
                'item_type' => EmployeePayrollItem::TYPE_TAX,
                'title' => 'مالیات حقوق',
                'amount' => $taxAmount,
                'is_auto' => true,
            ]);
        }
        if ($insAmount > 0) {
            $payroll->items()->create([
                'atelier_id' => $payroll->atelier_id,
                'item_type' => EmployeePayrollItem::TYPE_INSURANCE,
                'title' => 'بیمه سهم کارمند',
                'amount' => $insAmount,
                'is_auto' => true,
            ]);
        }
    }

    /**
     * جمع حضور ماه برای پر کردن روز/ساعت.
     *
     * @return array{days_present: float, hours_total: float}
     */
    public function attendanceSummary(int $employeeId, int $year, int $month): array
    {
        if (! Schema::hasTable('employee_attendance_days')) {
            return ['days_present' => 0, 'hours_total' => 0];
        }

        $rows = EmployeeAttendanceDay::query()
            ->where('shop_employee_id', $employeeId)
            ->where('attendance_year', $year)
            ->where('attendance_month', $month)
            ->get();

        $days = 0.0;
        $hours = 0.0;
        foreach ($rows as $row) {
            if ($row->status === EmployeeAttendanceDay::STATUS_PRESENT) {
                $days += 1;
                $hours += (float) ($row->hours ?? 0);
            }
        }

        return [
            'days_present' => $days,
            'hours_total' => round($hours, 2),
        ];
    }

    /**
     * آیا این نوع حقوق به ساعت نیاز دارد؟
     */
    public function requiresHours(string $salaryType): bool
    {
        return in_array($salaryType, [
            ShopEmployee::SALARY_TYPE_MONTHLY,
            ShopEmployee::SALARY_TYPE_HOURLY,
            ShopEmployee::SALARY_TYPE_MIXED,
        ], true);
    }

    public function requiresDays(string $salaryType): bool
    {
        return $salaryType === ShopEmployee::SALARY_TYPE_DAILY;
    }

    public function allowsZeroWork(string $salaryType): bool
    {
        return in_array($salaryType, [
            ShopEmployee::SALARY_TYPE_FIXED,
            ShopEmployee::SALARY_TYPE_COMMISSION,
        ], true);
    }
}
