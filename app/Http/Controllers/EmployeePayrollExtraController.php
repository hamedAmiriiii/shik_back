<?php

namespace App\Http\Controllers;

use App\Models\EmployeeAttendanceDay;
use App\Models\EmployeeLoan;
use App\Models\EmployeePayroll;
use App\Models\EmployeePayrollItem;
use App\Models\EmployeePayrollItemTemplate;
use App\Models\ShopEmployee;
use App\Services\PayrollSalaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmployeePayrollExtraController extends Controller
{
    public function bulkCalculate(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json(['message' => 'محاسبه گروهی فقط با حساب فروشگاه ممکن است.'], 422);
        }

        $fields = $request->validate([
            'payroll_year' => 'required|integer|min:1300|max:1700',
            'payroll_month' => 'required|integer|min:1|max:12',
            'shop_employee_ids' => 'nullable|array',
            'shop_employee_ids.*' => 'integer',
        ]);

        $year = (int) $fields['payroll_year'];
        $month = (int) $fields['payroll_month'];
        $service = app(PayrollSalaryService::class);

        $employeesQuery = ShopEmployee::query()
            ->where('atelier_id', $atelierId)
            ->where('is_active', true);

        if (! empty($fields['shop_employee_ids'])) {
            $employeesQuery->whereIn('id', $fields['shop_employee_ids']);
        }

        $employees = $employeesQuery->get();
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($employees as $employee) {
            try {
                $existing = EmployeePayroll::query()
                    ->where('shop_employee_id', $employee->id)
                    ->where('payroll_year', $year)
                    ->where('payroll_month', $month)
                    ->first();

                if ($existing && ! $existing->canRecalculateSalary()) {
                    $skipped++;
                    continue;
                }

                $attendance = $service->attendanceSummary($employee->id, $year, $month);
                $hours = $attendance['hours_total'];
                $days = $attendance['days_present'];
                $type = $employee->salaryType();

                if ($service->requiresHours($type) && $hours <= 0) {
                    $hours = (float) $employee->base_work_hours;
                }
                if ($service->requiresDays($type) && $days <= 0) {
                    $skipped++;
                    $errors[] = $employee->name.': روز کارکرد ثبت نشده';
                    continue;
                }
                if ($service->requiresHours($type) && $hours <= 0 && ! $service->allowsZeroWork($type)) {
                    $skipped++;
                    $errors[] = $employee->name.': ساعت کارکرد ثبت نشده';
                    continue;
                }

                $calc = $service->calculateBase($employee, $hours, $days, 0, $year, $month);

                $payload = [
                    'atelier_id' => $atelierId,
                    'hours_worked' => (float) ($calc['hours_worked'] ?? $hours),
                    'hourly_wage' => (float) $employee->hourly_wage,
                    'salary_amount' => $calc['salary_amount'],
                    'base_salary_snapshot' => $calc['base_salary_snapshot'],
                    'base_work_hours_snapshot' => $calc['base_work_hours_snapshot'],
                    'overtime_hours' => $calc['overtime_hours'],
                    'overtime_amount' => $calc['overtime_amount'],
                ];
                if (Schema::hasColumn('employee_payrolls', 'days_worked')) {
                    $payload['days_worked'] = $calc['days_worked'];
                }
                if (Schema::hasColumn('employee_payrolls', 'salary_type_snapshot')) {
                    $payload['salary_type_snapshot'] = $calc['salary_type'];
                }
                if (Schema::hasColumn('employee_payrolls', 'base_earned_amount')) {
                    $payload['base_earned_amount'] = $calc['base_earned_amount'];
                }
                if (Schema::hasColumn('employee_payrolls', 'commission_sales_amount')) {
                    $payload['commission_sales_amount'] = $calc['commission_sales_amount'];
                    $payload['commission_amount'] = $calc['commission_amount'];
                }

                $payroll = EmployeePayroll::updateOrCreate(
                    [
                        'shop_employee_id' => $employee->id,
                        'payroll_year' => $year,
                        'payroll_month' => $month,
                    ],
                    $payload
                );

                $service->applyRecurringAndLoans($payroll, $employee);
                $service->applyAutoTaxInsurance($payroll);
                $service->recomputePayrollTotals($payroll);

                if ($existing) {
                    $updated++;
                } else {
                    $created++;
                }
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = $employee->name.': '.$e->getMessage();
            }
        }

        return response([
            'message' => 'محاسبه گروهی انجام شد.',
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ], 200);
    }

    public function templatesIndex(Request $request, ShopEmployee $shopEmployee)
    {
        $this->assertModelBelongsToStaffAtelier($request, $shopEmployee);
        if (! Schema::hasTable('employee_payroll_item_templates')) {
            return response(['data' => []], 200);
        }

        return response([
            'data' => EmployeePayrollItemTemplate::query()
                ->where('shop_employee_id', $shopEmployee->id)
                ->orderByDesc('id')
                ->get(),
        ], 200);
    }

    public function templatesStore(Request $request, ShopEmployee $shopEmployee)
    {
        $this->assertModelBelongsToStaffAtelier($request, $shopEmployee);
        if (! Schema::hasTable('employee_payroll_item_templates')) {
            return response()->json(['message' => 'جدول قالب‌ها وجود ندارد.'], 503);
        }

        $fields = $request->validate([
            'item_type' => 'required|string|in:'.implode(',', EmployeePayrollItem::types()),
            'title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'note' => 'nullable|string|max:2000',
        ]);

        $tpl = EmployeePayrollItemTemplate::create([
            'atelier_id' => $shopEmployee->atelier_id,
            'shop_employee_id' => $shopEmployee->id,
            'item_type' => $fields['item_type'],
            'title' => $fields['title'],
            'amount' => abs((float) $fields['amount']),
            'is_active' => array_key_exists('is_active', $fields) ? (bool) $fields['is_active'] : true,
            'note' => $fields['note'] ?? null,
        ]);

        return response($tpl, 201);
    }

    public function templatesDestroy(Request $request, ShopEmployee $shopEmployee, EmployeePayrollItemTemplate $template)
    {
        $this->assertModelBelongsToStaffAtelier($request, $shopEmployee);
        if ((int) $template->shop_employee_id !== (int) $shopEmployee->id) {
            return response()->json(['message' => 'قالب متعلق به این کارمند نیست.'], 422);
        }
        $template->delete();

        return response(['message' => 'قالب حذف شد.'], 200);
    }

    public function loansIndex(Request $request, ShopEmployee $shopEmployee)
    {
        $this->assertModelBelongsToStaffAtelier($request, $shopEmployee);
        if (! Schema::hasTable('employee_loans')) {
            return response(['data' => []], 200);
        }

        return response([
            'data' => EmployeeLoan::query()
                ->where('shop_employee_id', $shopEmployee->id)
                ->orderByDesc('id')
                ->get(),
        ], 200);
    }

    public function loansStore(Request $request, ShopEmployee $shopEmployee)
    {
        $this->assertModelBelongsToStaffAtelier($request, $shopEmployee);
        if (! Schema::hasTable('employee_loans')) {
            return response()->json(['message' => 'جدول وام وجود ندارد.'], 503);
        }

        $fields = $request->validate([
            'title' => 'nullable|string|max:255',
            'principal' => 'required|numeric|min:1',
            'monthly_installment' => 'required|numeric|min:1',
            'start_year' => 'nullable|integer|min:1300|max:1700',
            'start_month' => 'nullable|integer|min:1|max:12',
            'note' => 'nullable|string|max:2000',
        ]);

        $principal = abs((float) $fields['principal']);
        $loan = EmployeeLoan::create([
            'atelier_id' => $shopEmployee->atelier_id,
            'shop_employee_id' => $shopEmployee->id,
            'title' => $fields['title'] ?? 'وام کارمند',
            'principal' => $principal,
            'monthly_installment' => abs((float) $fields['monthly_installment']),
            'remaining' => $principal,
            'status' => EmployeeLoan::STATUS_ACTIVE,
            'start_year' => $fields['start_year'] ?? null,
            'start_month' => $fields['start_month'] ?? null,
            'note' => $fields['note'] ?? null,
        ]);

        return response($loan, 201);
    }

    public function loansClose(Request $request, ShopEmployee $shopEmployee, EmployeeLoan $loan)
    {
        $this->assertModelBelongsToStaffAtelier($request, $shopEmployee);
        if ((int) $loan->shop_employee_id !== (int) $shopEmployee->id) {
            return response()->json(['message' => 'وام متعلق به این کارمند نیست.'], 422);
        }
        $loan->update(['status' => EmployeeLoan::STATUS_CLOSED]);

        return response(['message' => 'وام بسته شد.', 'loan' => $loan->fresh()], 200);
    }

    public function attendanceIndex(Request $request, ShopEmployee $shopEmployee)
    {
        $this->assertModelBelongsToStaffAtelier($request, $shopEmployee);
        if (! Schema::hasTable('employee_attendance_days')) {
            return response(['data' => [], 'summary' => ['days_present' => 0, 'hours_total' => 0]], 200);
        }

        $year = (int) $request->input('year', $request->input('payroll_year'));
        $month = (int) $request->input('month', $request->input('payroll_month'));
        if ($year < 1300 || $month < 1 || $month > 12) {
            return response()->json(['message' => 'سال و ماه الزامی است.'], 422);
        }

        $rows = EmployeeAttendanceDay::query()
            ->where('shop_employee_id', $shopEmployee->id)
            ->where('attendance_year', $year)
            ->where('attendance_month', $month)
            ->orderBy('attendance_day')
            ->get();

        $summary = app(PayrollSalaryService::class)->attendanceSummary($shopEmployee->id, $year, $month);

        return response(['data' => $rows, 'summary' => $summary], 200);
    }

    public function attendanceUpsert(Request $request, ShopEmployee $shopEmployee)
    {
        $this->assertModelBelongsToStaffAtelier($request, $shopEmployee);
        if (! Schema::hasTable('employee_attendance_days')) {
            return response()->json(['message' => 'جدول حضور وجود ندارد.'], 503);
        }

        $fields = $request->validate([
            'year' => 'required|integer|min:1300|max:1700',
            'month' => 'required|integer|min:1|max:12',
            'day' => 'required|integer|min:1|max:31',
            'status' => 'required|in:present,absent,leave',
            'hours' => 'nullable|numeric|min:0|max:24',
            'note' => 'nullable|string|max:500',
        ]);

        $row = EmployeeAttendanceDay::updateOrCreate(
            [
                'shop_employee_id' => $shopEmployee->id,
                'attendance_year' => (int) $fields['year'],
                'attendance_month' => (int) $fields['month'],
                'attendance_day' => (int) $fields['day'],
            ],
            [
                'atelier_id' => $shopEmployee->atelier_id,
                'status' => $fields['status'],
                'hours' => $fields['hours'] ?? null,
                'note' => $fields['note'] ?? null,
            ]
        );

        $summary = app(PayrollSalaryService::class)->attendanceSummary(
            $shopEmployee->id,
            (int) $fields['year'],
            (int) $fields['month']
        );

        return response(['day' => $row, 'summary' => $summary], 200);
    }

    public function attendanceBulk(Request $request, ShopEmployee $shopEmployee)
    {
        $this->assertModelBelongsToStaffAtelier($request, $shopEmployee);
        if (! Schema::hasTable('employee_attendance_days')) {
            return response()->json(['message' => 'جدول حضور وجود ندارد.'], 503);
        }

        $fields = $request->validate([
            'year' => 'required|integer|min:1300|max:1700',
            'month' => 'required|integer|min:1|max:12',
            'days' => 'required|array|min:1',
            'days.*.day' => 'required|integer|min:1|max:31',
            'days.*.status' => 'required|in:present,absent,leave',
            'days.*.hours' => 'nullable|numeric|min:0|max:24',
        ]);

        DB::transaction(function () use ($fields, $shopEmployee) {
            foreach ($fields['days'] as $day) {
                EmployeeAttendanceDay::updateOrCreate(
                    [
                        'shop_employee_id' => $shopEmployee->id,
                        'attendance_year' => (int) $fields['year'],
                        'attendance_month' => (int) $fields['month'],
                        'attendance_day' => (int) $day['day'],
                    ],
                    [
                        'atelier_id' => $shopEmployee->atelier_id,
                        'status' => $day['status'],
                        'hours' => $day['hours'] ?? null,
                    ]
                );
            }
        });

        $summary = app(PayrollSalaryService::class)->attendanceSummary(
            $shopEmployee->id,
            (int) $fields['year'],
            (int) $fields['month']
        );

        return response(['message' => 'حضور ثبت شد.', 'summary' => $summary], 200);
    }
}
