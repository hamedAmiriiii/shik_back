<?php

namespace App\Http\Controllers;

use App\Models\EmployeePayroll;
use App\Models\Expense;
use App\Models\Setting;
use App\Models\ShopEmployee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmployeePayrollController extends Controller
{
    public function index(Request $request)
    {
        if (! Schema::hasTable('employee_payrolls')) {
            return response()->json([
                'message' => 'جدول employee_payrolls وجود ندارد. migration یا SQL را اجرا کنید.',
            ], 503);
        }

        $atelierId = $this->shopAtelierIdOrAbort($request);

        if (! Schema::hasTable('shop_employees')) {
            return response()->json([
                'message' => 'جدول shop_employees وجود ندارد. migration یا SQL را اجرا کنید.',
            ], 503);
        }

        $filterQuery = EmployeePayroll::query()->where('atelier_id', $atelierId);
        $this->applyListFilters($request, $filterQuery);

        $perPage = (int) $request->input('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;

        try {
            $stats = (clone $filterQuery)
                ->reorder()
                ->selectRaw('
                    COUNT(*) as payroll_count,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as paid_count,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as partial_count,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending_count,
                    COALESCE(SUM(salary_amount), 0) as total_salary_amount
                ', [EmployeePayroll::STATUS_PAID, EmployeePayroll::STATUS_PARTIAL, EmployeePayroll::STATUS_PENDING])
                ->first();

            $paginator = (clone $filterQuery)
                ->with(['employee', 'payments'])
                ->orderByDesc('payroll_year')
                ->orderByDesc('payroll_month')
                ->orderByDesc('id')
                ->paginate($perPage);
        } catch (QueryException $e) {
            $payload = [
                'message' => 'خطا در خواندن لیست حقوق. جداول کارمندان را بررسی کنید.',
            ];
            if (config('app.debug')) {
                $payload['error'] = $e->getMessage();
            }

            return response()->json($payload, 503);
        }

        $payload = $paginator->toArray();

        foreach ($payload['data'] as &$row) {
            $payroll = $paginator->getCollection()->firstWhere('id', $row['id']);
            if ($payroll) {
                $totalPaid = (float) $payroll->payments->sum('amount');
                $totalAdvances = (float) $payroll->payments
                    ->where('payment_type', \App\Models\EmployeePayrollPayment::TYPE_ADVANCE)
                    ->sum('amount');
                $row['total_paid'] = round($totalPaid, 2);
                $row['total_advances'] = round($totalAdvances, 2);
                $row['remaining'] = $payroll->remaining();
                $row['overpaid_amount'] = $payroll->overpaidAmount();
                $row['salary_calculated'] = $payroll->isSalaryCalculated();
                $row['can_recalculate'] = $payroll->canRecalculateSalary();
                $row['payments'] = $payroll->payments->values();
            }
        }
        unset($row);
        $payload['meta'] = array_merge($payload['meta'] ?? [], [
            'total_salary_amount' => (float) round((float) ($stats->total_salary_amount ?? 0), 2),
            'payroll_count' => (int) ($stats->payroll_count ?? 0),
            'paid_count' => (int) ($stats->paid_count ?? 0),
            'partial_count' => (int) ($stats->partial_count ?? 0),
            'pending_count' => (int) ($stats->pending_count ?? 0),
            'filters' => [
                'payroll_year' => $this->payrollYearFromRequest($request),
                'payroll_month' => $this->payrollMonthFromRequest($request),
            ],
        ]);

        return response($payload, 200);
    }

    public function store(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json([
                'message' => 'ثبت کارکرد فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422);
        }

        $this->mergePayrollInputAliases($request);

        $fields = $request->validate([
            'shop_employee_id' => 'required|integer|exists:shop_employees,id',
            'payroll_year' => 'required|integer|min:1300|max:1700',
            'payroll_month' => 'required|integer|min:1|max:12',
            'hours_worked' => 'nullable|numeric|min:0|max:744',
            'days_worked' => 'nullable|numeric|min:0|max:31',
            'overtime_hours' => 'nullable|numeric|min:0|max:744',
            'hourly_wage' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:2000',
        ]);

        $employee = ShopEmployee::query()
            ->where('id', (int) $fields['shop_employee_id'])
            ->where('atelier_id', $atelierId)
            ->first();

        if (! $employee) {
            return response()->json(['message' => 'کارمند متعلق به این فروشگاه نیست.'], 422);
        }

        $hoursWorked = (float) ($fields['hours_worked'] ?? 0);
        $daysWorked = (float) ($fields['days_worked'] ?? 0);
        $overtimeHoursInput = (float) ($fields['overtime_hours'] ?? 0);

        if ($employee->isDailySalary()) {
            if ($daysWorked <= 0) {
                return response()->json(['message' => 'تعداد روز کارکرد را وارد کنید.'], 422);
            }
            $calc = $employee->calculateSalary(0, $daysWorked, $overtimeHoursInput);
            $hoursWorked = 0;
        } else {
            if ($hoursWorked <= 0) {
                return response()->json(['message' => 'ساعت کارکرد را وارد کنید.'], 422);
            }
            $calc = $employee->calculateSalary($hoursWorked);
            $daysWorked = 0;
        }

        // نرخ ساعتی override در صورت ارسال دستی (فقط ماهانه / اضافه‌کار)
        if (array_key_exists('hourly_wage', $fields) && (float) $fields['hourly_wage'] > 0) {
            $hourlyWage = (float) $fields['hourly_wage'];
            if ($employee->isDailySalary()) {
                $calc['overtime_amount'] = round($hourlyWage * $calc['overtime_hours'], 2);
                $calc['salary_amount'] = round(
                    ((float) $calc['base_salary_snapshot'] * (float) $calc['days_worked']) + $calc['overtime_amount'],
                    2
                );
            } else {
                $calc['overtime_amount'] = round($hourlyWage * $calc['overtime_hours'], 2);
                $calc['salary_amount'] = round($calc['base_salary_snapshot'] > 0
                    ? (($hoursWorked >= $calc['base_work_hours_snapshot'] && $calc['base_work_hours_snapshot'] > 0)
                        ? $calc['base_salary_snapshot'] + $calc['overtime_amount']
                        : ($calc['base_work_hours_snapshot'] > 0
                            ? round(($calc['base_salary_snapshot'] / $calc['base_work_hours_snapshot']) * $hoursWorked, 2) + $calc['overtime_amount']
                            : $calc['overtime_amount']))
                    : $hourlyWage * $hoursWorked, 2);
            }
        } else {
            $hourlyWage = (float) $employee->hourly_wage;
        }

        $existing = EmployeePayroll::query()
            ->where('shop_employee_id', $employee->id)
            ->where('payroll_year', (int) $fields['payroll_year'])
            ->where('payroll_month', (int) $fields['payroll_month'])
            ->first();

        // مساعده مانع محاسبه مجدد حقوق نیست؛ فقط بعد از پرداخت «حقوق» قفل می‌شود
        if ($existing && ! $existing->canRecalculateSalary()) {
            return response()->json([
                'message' => 'برای این ماه پرداخت حقوق ثبت شده و کارکرد قابل تغییر نیست. مساعده‌ها از مانده حقوق کسر می‌شوند.',
            ], 422);
        }

        $payload = [
            'atelier_id' => $atelierId,
            'hours_worked' => $hoursWorked,
            'hourly_wage' => $hourlyWage,
            'salary_amount' => $calc['salary_amount'],
            'base_salary_snapshot' => $calc['base_salary_snapshot'],
            'base_work_hours_snapshot' => $calc['base_work_hours_snapshot'],
            'overtime_hours' => $calc['overtime_hours'],
            'overtime_amount' => $calc['overtime_amount'],
            'note' => $fields['note'] ?? null,
        ];
        if (Schema::hasColumn('employee_payrolls', 'days_worked')) {
            $payload['days_worked'] = $calc['days_worked'];
        }
        if (Schema::hasColumn('employee_payrolls', 'salary_type_snapshot')) {
            $payload['salary_type_snapshot'] = $calc['salary_type'];
        }

        $payroll = EmployeePayroll::updateOrCreate(
            [
                'shop_employee_id' => $employee->id,
                'payroll_year' => (int) $fields['payroll_year'],
                'payroll_month' => (int) $fields['payroll_month'],
            ],
            $payload
        );

        // بعد از محاسبه حقوق، مساعده‌های قبلی از مانده کسر و وضعیت همگام می‌شود
        $payroll->syncStatus();
        $payroll->load(['employee', 'payments']);

        return response(array_merge($payroll->toArray(), $payroll->paymentSummary(), [
            'salary_breakdown' => [
                'base_salary' => $calc['base_salary_snapshot'],
                'base_work_hours' => $calc['base_work_hours_snapshot'],
                'overtime_hours' => $calc['overtime_hours'],
                'overtime_amount' => $calc['overtime_amount'],
                'days_worked' => $calc['days_worked'],
                'salary_type' => $calc['salary_type'],
            ],
        ]), 201);
    }

    public function show(Request $request, EmployeePayroll $employeePayroll)
    {
        $this->assertModelBelongsToStaffAtelier($request, $employeePayroll);

        $payroll = $employeePayroll->load([
            'employee',
            'paidBy:id,name,last_name',
            'expense:id,title,amount,date,type',
            'payments.paidBy:id,name,last_name',
        ]);

        return response(array_merge($payroll->toArray(), $employeePayroll->paymentSummary()), 200);
    }

    public function update(Request $request, EmployeePayroll $employeePayroll)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json([
                'message' => 'ویرایش کارکرد فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422);
        }

        $this->assertModelBelongsToStaffAtelier($request, $employeePayroll);

        if (! $employeePayroll->canRecalculateSalary()) {
            return response()->json([
                'message' => 'پس از ثبت پرداخت حقوق، ساعت‌کاری قابل ویرایش نیست. مساعده‌ها از مانده حقوق کسر می‌شوند.',
            ], 422);
        }

        $this->mergePayrollInputAliases($request);

        $fields = $request->validate([
            'payroll_year' => 'sometimes|required|integer|min:1300|max:1700',
            'payroll_month' => 'sometimes|required|integer|min:1|max:12',
            'hours_worked' => 'sometimes|nullable|numeric|min:0|max:744',
            'days_worked' => 'sometimes|nullable|numeric|min:0|max:31',
            'overtime_hours' => 'sometimes|nullable|numeric|min:0|max:744',
            'hourly_wage' => 'sometimes|nullable|numeric|min:0',
            'note' => 'sometimes|nullable|string|max:2000',
        ]);

        $payrollYear = array_key_exists('payroll_year', $fields)
            ? (int) $fields['payroll_year']
            : (int) $employeePayroll->payroll_year;
        $payrollMonth = array_key_exists('payroll_month', $fields)
            ? (int) $fields['payroll_month']
            : (int) $employeePayroll->payroll_month;

        $duplicate = EmployeePayroll::query()
            ->where('shop_employee_id', $employeePayroll->shop_employee_id)
            ->where('payroll_year', $payrollYear)
            ->where('payroll_month', $payrollMonth)
            ->where('id', '!=', $employeePayroll->id)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'برای این کارمند در این ماه قبلاً کارکرد ثبت شده است.',
            ], 422);
        }

        $employee = $employeePayroll->employee;
        $hoursWorked = array_key_exists('hours_worked', $fields)
            ? (float) $fields['hours_worked']
            : (float) $employeePayroll->hours_worked;
        $daysWorked = array_key_exists('days_worked', $fields)
            ? (float) $fields['days_worked']
            : (float) ($employeePayroll->days_worked ?? 0);
        $overtimeHoursInput = array_key_exists('overtime_hours', $fields)
            ? (float) $fields['overtime_hours']
            : (float) ($employeePayroll->overtime_hours ?? 0);

        if ($employee && $employee->isDailySalary()) {
            if ($daysWorked <= 0) {
                return response()->json(['message' => 'تعداد روز کارکرد را وارد کنید.'], 422);
            }
            $calc = $employee->calculateSalary(0, $daysWorked, $overtimeHoursInput);
            $hoursWorked = 0;
        } elseif ($employee) {
            if ($hoursWorked <= 0) {
                return response()->json(['message' => 'ساعت کارکرد را وارد کنید.'], 422);
            }
            $calc = $employee->calculateSalary($hoursWorked);
            $daysWorked = 0;
        } else {
            $calc = [
                'salary_amount' => round((float) $employeePayroll->hourly_wage * $hoursWorked, 2),
                'base_salary_snapshot' => (float) $employeePayroll->base_salary_snapshot,
                'base_work_hours_snapshot' => (float) $employeePayroll->base_work_hours_snapshot,
                'overtime_hours' => 0,
                'overtime_amount' => 0,
                'days_worked' => 0,
                'salary_type' => ShopEmployee::SALARY_TYPE_MONTHLY,
            ];
        }

        $hourlyWage = array_key_exists('hourly_wage', $fields) && (float) $fields['hourly_wage'] > 0
            ? (float) $fields['hourly_wage']
            : (float) ($employee->hourly_wage ?? $employeePayroll->hourly_wage);

        if (array_key_exists('hourly_wage', $fields) && (float) $fields['hourly_wage'] > 0) {
            $calc['overtime_amount'] = round($hourlyWage * $calc['overtime_hours'], 2);
            if (($calc['salary_type'] ?? '') === ShopEmployee::SALARY_TYPE_DAILY) {
                $calc['salary_amount'] = round(
                    ((float) $calc['base_salary_snapshot'] * (float) $calc['days_worked']) + $calc['overtime_amount'],
                    2
                );
            } else {
                $baseSnap = (float) $calc['base_salary_snapshot'];
                $baseHoursSnap = (float) $calc['base_work_hours_snapshot'];
                if ($baseSnap > 0) {
                    if ($hoursWorked >= $baseHoursSnap && $baseHoursSnap > 0) {
                        $calc['salary_amount'] = round($baseSnap + $calc['overtime_amount'], 2);
                    } elseif ($baseHoursSnap > 0) {
                        $calc['salary_amount'] = round(($baseSnap / $baseHoursSnap) * $hoursWorked + $calc['overtime_amount'], 2);
                    } else {
                        $calc['salary_amount'] = round($calc['overtime_amount'], 2);
                    }
                } else {
                    $calc['salary_amount'] = round($hourlyWage * $hoursWorked, 2);
                }
            }
        }

        $update = [
            'payroll_year' => $payrollYear,
            'payroll_month' => $payrollMonth,
            'hours_worked' => $hoursWorked,
            'hourly_wage' => $hourlyWage,
            'salary_amount' => $calc['salary_amount'],
            'base_salary_snapshot' => $calc['base_salary_snapshot'],
            'base_work_hours_snapshot' => $calc['base_work_hours_snapshot'],
            'overtime_hours' => $calc['overtime_hours'],
            'overtime_amount' => $calc['overtime_amount'],
            'note' => array_key_exists('note', $fields) ? $fields['note'] : $employeePayroll->note,
        ];
        if (Schema::hasColumn('employee_payrolls', 'days_worked')) {
            $update['days_worked'] = $calc['days_worked'];
        }
        if (Schema::hasColumn('employee_payrolls', 'salary_type_snapshot')) {
            $update['salary_type_snapshot'] = $calc['salary_type'];
        }

        $employeePayroll->update($update);

        $employeePayroll->syncStatus();

        return response([
            'message' => 'کارکرد ماهانه با موفقیت به‌روزرسانی شد.',
            'payroll' => array_merge(
                $employeePayroll->fresh()->load(['employee', 'payments'])->toArray(),
                $employeePayroll->paymentSummary()
            ),
        ], 200);
    }

    public function destroy(Request $request, EmployeePayroll $employeePayroll)
    {
        $this->assertModelBelongsToStaffAtelier($request, $employeePayroll);

        if ($employeePayroll->hasSalaryPayments()) {
            return response()->json([
                'message' => 'این فیش پرداخت حقوق دارد و قابل حذف نیست. ابتدا پرداخت‌های حقوق را حذف کنید.',
            ], 422);
        }

        DB::transaction(function () use ($employeePayroll) {
            $employeePayroll->load('payments');
            foreach ($employeePayroll->payments as $payment) {
                if ($payment->expense_id) {
                    Expense::where('id', $payment->expense_id)->delete();
                }
                $payment->delete();
            }
            $employeePayroll->delete();
        });

        return response(['message' => 'کارکرد ماهانه با موفقیت حذف شد.'], 200);
    }

    protected function applyListFilters(Request $request, Builder $query): void
    {
        $payrollYear = $this->payrollYearFromRequest($request);
        $payrollMonth = $this->payrollMonthFromRequest($request);

        if ($payrollYear !== null) {
            $query->where('payroll_year', $payrollYear);
        }
        if ($payrollMonth !== null) {
            $query->where('payroll_month', $payrollMonth);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $employeeId = $request->input('shop_employee_id', $request->input('employee_id'));
        if ($employeeId !== null && $employeeId !== '') {
            $query->where('shop_employee_id', (int) $employeeId);
        }

        $searchDataModel = json_decode($request->input('searchFilterModel') ?? '');
        if ($searchDataModel) {
            $query->where(function ($q) use ($searchDataModel) {
                if (is_object($searchDataModel)) {
                    if (isset($searchDataModel->employee_name)) {
                        $employeeName = $searchDataModel->employee_name;
                        $q->whereHas('employee', function ($eq) use ($employeeName) {
                            $eq->where('name', 'like', '%'.$employeeName.'%');
                        });
                    }
                    if (isset($searchDataModel->status)) {
                        $q->where('status', $searchDataModel->status);
                    }
                    if (isset($searchDataModel->year) || isset($searchDataModel->payroll_year)) {
                        $q->where('payroll_year', (int) ($searchDataModel->payroll_year ?? $searchDataModel->year));
                    }
                    if (isset($searchDataModel->month) || isset($searchDataModel->payroll_month)) {
                        $q->where('payroll_month', (int) ($searchDataModel->payroll_month ?? $searchDataModel->month));
                    }
                } elseif (is_string($searchDataModel) && $searchDataModel !== '') {
                    $q->whereHas('employee', function ($eq) use ($searchDataModel) {
                        $eq->where('name', 'like', '%'.$searchDataModel.'%')
                            ->orWhere('phone', 'like', '%'.$searchDataModel.'%');
                    });
                }
            });
        }
    }

    protected function payrollYearFromRequest(Request $request): ?int
    {
        $value = $request->input('payroll_year', $request->input('year'));

        return ($value !== null && $value !== '') ? (int) $value : null;
    }

    protected function payrollMonthFromRequest(Request $request): ?int
    {
        $value = $request->input('payroll_month', $request->input('month'));

        return ($value !== null && $value !== '') ? (int) $value : null;
    }

    protected function mergePayrollInputAliases(Request $request): void
    {
        $this->mergeRequestPayload($request, [
            'shop_employee_id',
            'employee_id',
            'payroll_year',
            'payroll_month',
            'year',
            'month',
            'hours_worked',
            'hourly_wage',
            'note',
        ]);

        if (! $request->filled('payroll_year') && $request->filled('year')) {
            $request->merge(['payroll_year' => $request->input('year')]);
        }
        if (! $request->filled('payroll_month') && $request->filled('month')) {
            $request->merge(['payroll_month' => $request->input('month')]);
        }
        if (! $request->filled('shop_employee_id') && $request->filled('employee_id')) {
            $request->merge(['shop_employee_id' => $request->input('employee_id')]);
        }
    }

    protected function resolveHourlyWage(int $atelierId, array $fields, float $fallback = 0.0): float
    {
        if (array_key_exists('hourly_wage', $fields) && (float) $fields['hourly_wage'] > 0) {
            return (float) $fields['hourly_wage'];
        }

        if ($fallback > 0) {
            return $fallback;
        }

        Setting::setContextAtelierId($atelierId);

        return (float) Setting::get('salary_hourly_wage', '0');
    }
}
