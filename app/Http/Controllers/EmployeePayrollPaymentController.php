<?php

namespace App\Http\Controllers;

use App\Models\EmployeePayroll;
use App\Models\EmployeePayrollPayment;
use App\Models\Expense;
use App\Models\ShopEmployee;
use App\Services\DocumentPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class EmployeePayrollPaymentController extends Controller
{
    /**
     * لیست پرداخت‌های یک فیش حقوقی
     */
    public function index(Request $request, EmployeePayroll $employeePayroll)
    {
        $this->assertModelBelongsToStaffAtelier($request, $employeePayroll);

        $payments = $employeePayroll->payments()
            ->with(['paidBy:id,name,last_name', 'expense'])
            ->orderBy('created_at')
            ->get();

        return response(array_merge([
            'payroll_id' => $employeePayroll->id,
            'payments' => $payments,
        ], $employeePayroll->paymentSummary()), 200);
    }

    /**
     * مساعده قبل از محاسبه حقوق (و بعد از آن).
     *
     * اگر فیش ماه وجود نداشته باشد ساخته می‌شود؛ ساعت‌کاری لازم نیست.
     * هر مساعده سند هزینه جدا می‌خورد.
     *
     * POST /api/employee-payrolls/advances
     */
    public function storeAdvance(Request $request)
    {
        $actor = $this->requireStaffShopUser($request);
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json([
                'message' => 'ثبت مساعده فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422);
        }

        $this->mergeRequestPayload($request, [
            'shop_employee_id',
            'employee_id',
            'payroll_year',
            'payroll_month',
            'year',
            'month',
            'amount',
            'title',
            'note',
            'shop_account_id',
            'payment_method',
        ]);

        if (! $request->filled('shop_employee_id') && $request->filled('employee_id')) {
            $request->merge(['shop_employee_id' => $request->input('employee_id')]);
        }
        if (! $request->filled('payroll_year') && $request->filled('year')) {
            $request->merge(['payroll_year' => $request->input('year')]);
        }
        if (! $request->filled('payroll_month') && $request->filled('month')) {
            $request->merge(['payroll_month' => $request->input('month')]);
        }

        $fields = $request->validate(array_merge([
            'shop_employee_id' => 'required|integer|exists:shop_employees,id',
            'payroll_year' => 'required|integer|min:1300|max:1700',
            'payroll_month' => 'required|integer|min:1|max:12',
            'amount' => 'required|numeric|min:1',
            'title' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:2000',
        ], $this->paymentAccountRules('expenses'), DocumentPaymentService::requestRules()));

        $employee = ShopEmployee::query()
            ->where('id', (int) $fields['shop_employee_id'])
            ->where('atelier_id', $atelierId)
            ->first();

        if (! $employee) {
            return response()->json(['message' => 'کارمند متعلق به این فروشگاه نیست.'], 422);
        }

        $accountError = $this->paymentAccountError($atelierId, $fields['shop_account_id'] ?? null);
        if ($accountError) {
            return response()->json(['message' => $accountError], 422);
        }

        $payment = null;
        $payroll = null;

        try {
            DB::transaction(function () use ($employee, $actor, $fields, $atelierId, &$payment, &$payroll) {
                $payroll = $this->findOrCreatePayrollStub(
                    $employee,
                    $atelierId,
                    (int) $fields['payroll_year'],
                    (int) $fields['payroll_month']
                );

                $payment = $this->recordPayment(
                    $payroll,
                    $actor,
                    EmployeePayrollPayment::TYPE_ADVANCE,
                    (float) $fields['amount'],
                    $fields
                );
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        }

        $payroll->refresh();

        return response(array_merge([
            'message' => 'مساعده ثبت شد و در هزینه‌ها سند خورد. پس از محاسبه حقوق از مانده کسر می‌شود.',
            'payment' => $payment->load(['paidBy:id,name,last_name', 'expense']),
            'payroll' => array_merge(
                $payroll->load(['employee', 'payments'])->toArray(),
                $payroll->paymentSummary()
            ),
        ], $payroll->paymentSummary()), 201);
    }

    /**
     * ثبت پرداخت (بخشی از حقوق، مساعده، سایر)
     *
     * مساعده می‌تواند قبل از محاسبه حقوق و حتی بیشتر از حقوق باشد؛ بعداً از حقوق محاسبه‌شده کسر می‌شود.
     * پرداخت نوع salary فقط بعد از محاسبه حقوق و تا سقف مانده (پس از کسر مساعده) مجاز است.
     */
    public function store(Request $request, EmployeePayroll $employeePayroll)
    {
        $actor = $this->requireStaffShopUser($request);
        $this->assertModelBelongsToStaffAtelier($request, $employeePayroll);

        $this->mergeRequestPayload($request, [
            'amount',
            'payment_type',
            'title',
            'note',
            'shop_account_id',
            'payment_method',
        ]);

        $fields = $request->validate(array_merge([
            'amount' => 'required|numeric|min:1',
            'payment_type' => 'nullable|string|in:salary,advance,other',
            'title' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:2000',
        ], $this->paymentAccountRules('expenses'), DocumentPaymentService::requestRules()));

        $paymentType = $fields['payment_type'] ?? EmployeePayrollPayment::TYPE_SALARY;
        $amount = (float) $fields['amount'];

        $accountError = $this->paymentAccountError((int) $employeePayroll->atelier_id, $fields['shop_account_id'] ?? null);
        if ($accountError) {
            return response()->json(['message' => $accountError], 422);
        }

        if ($paymentType === EmployeePayrollPayment::TYPE_SALARY && ! $employeePayroll->isSalaryCalculated()) {
            return response()->json([
                'message' => 'ابتدا حقوق این ماه را محاسبه کنید. مساعده را می‌توانید قبل از محاسبه هم ثبت کنید.',
            ], 422);
        }

        if ($paymentType === EmployeePayrollPayment::TYPE_SALARY && $employeePayroll->isPaid()) {
            return response()->json([
                'message' => 'حقوق این ماه کاملاً تسویه شده است (مساعده‌ها از حقوق کسر شده‌اند).',
            ], 422);
        }

        $payment = null;

        try {
            DB::transaction(function () use ($employeePayroll, $actor, $fields, $paymentType, $amount, &$payment) {
                $locked = EmployeePayroll::query()
                    ->where('id', $employeePayroll->id)
                    ->lockForUpdate()
                    ->with('employee:id,atelier_id,name,phone')
                    ->first();

                if (! $locked) {
                    abort(response()->json(['message' => 'فیش حقوقی یافت نشد.'], 404));
                }

                if ($paymentType === EmployeePayrollPayment::TYPE_SALARY) {
                    $remaining = $locked->remaining();
                    if ($remaining <= 0.01) {
                        abort(response()->json([
                            'message' => 'مانده‌ای برای پرداخت حقوق باقی نمانده است. مساعده‌های ثبت‌شده از حقوق کسر شده‌اند.',
                            'total_advances' => $locked->totalAdvances(),
                            'remaining' => $remaining,
                        ], 422));
                    }
                    if ($amount > $remaining + 0.01) {
                        abort(response()->json([
                            'message' => "مبلغ پرداخت حقوق ({$amount}) بیشتر از مانده پس از کسر مساعده ({$remaining}) است.",
                            'total_advances' => $locked->totalAdvances(),
                            'remaining' => $remaining,
                        ], 422));
                    }
                }

                $payment = $this->recordPayment($locked, $actor, $paymentType, $amount, $fields);
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        }

        $employeePayroll->refresh();

        return response(array_merge([
            'message' => $paymentType === EmployeePayrollPayment::TYPE_ADVANCE
                ? 'مساعده ثبت شد، در هزینه‌ها سند خورد و از حقوق محاسبه‌شده کسر خواهد شد.'
                : 'پرداخت با موفقیت ثبت شد و در هزینه‌ها سند خورد.',
            'payment' => $payment->load(['paidBy:id,name,last_name', 'expense']),
        ], $employeePayroll->paymentSummary()), 201);
    }

    /**
     * حذف پرداخت
     */
    public function destroy(Request $request, EmployeePayroll $employeePayroll, EmployeePayrollPayment $payment)
    {
        $this->assertModelBelongsToStaffAtelier($request, $employeePayroll);

        if ($payment->payroll_id !== $employeePayroll->id) {
            return response()->json(['message' => 'این پرداخت متعلق به این فیش نیست.'], 422);
        }

        DB::transaction(function () use ($employeePayroll, $payment) {
            if ($payment->expense_id) {
                Expense::where('id', $payment->expense_id)->delete();
            }
            $payment->delete();
            $employeePayroll->syncStatus();
        });

        $employeePayroll->refresh();

        return response(array_merge([
            'message' => 'پرداخت حذف شد.',
        ], $employeePayroll->paymentSummary()), 200);
    }

    /**
     * فیش ماه را پیدا می‌کند یا بدون محاسبه حقوق می‌سازد.
     */
    protected function findOrCreatePayrollStub(
        ShopEmployee $employee,
        int $atelierId,
        int $year,
        int $month
    ): EmployeePayroll {
        $payroll = EmployeePayroll::query()
            ->where('shop_employee_id', $employee->id)
            ->where('payroll_year', $year)
            ->where('payroll_month', $month)
            ->lockForUpdate()
            ->with('employee:id,atelier_id,name,phone')
            ->first();

        if ($payroll) {
            return $payroll;
        }

        try {
            $payroll = EmployeePayroll::create([
                'atelier_id' => $atelierId,
                'shop_employee_id' => $employee->id,
                'payroll_year' => $year,
                'payroll_month' => $month,
                'hours_worked' => 0,
                'hourly_wage' => (float) $employee->hourly_wage,
                'salary_amount' => 0,
                'base_salary_snapshot' => (float) $employee->base_salary,
                'base_work_hours_snapshot' => (float) $employee->base_work_hours,
                'overtime_hours' => 0,
                'overtime_amount' => 0,
                'status' => EmployeePayroll::STATUS_PENDING,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $payroll = EmployeePayroll::query()
                ->where('shop_employee_id', $employee->id)
                ->where('payroll_year', $year)
                ->where('payroll_month', $month)
                ->lockForUpdate()
                ->with('employee:id,atelier_id,name,phone')
                ->first();
            if (! $payroll) {
                throw $e;
            }

            return $payroll;
        }

        return $payroll->load('employee:id,atelier_id,name,phone');
    }

    /**
     * ثبت پرداخت + سند هزینه. مساعده سقفی ندارد.
     *
     * @param  array<string, mixed>  $fields
     */
    protected function recordPayment(
        EmployeePayroll $payroll,
        $actor,
        string $paymentType,
        float $amount,
        array $fields
    ): EmployeePayrollPayment {
        $userName = trim(($actor->name ?? '').' '.($actor->last_name ?? ''));
        if ($userName === '') {
            $userName = 'کاربر سیستم';
        }

        $titleMap = [
            EmployeePayrollPayment::TYPE_SALARY => 'پرداخت حقوق',
            EmployeePayrollPayment::TYPE_ADVANCE => 'مساعده',
            EmployeePayrollPayment::TYPE_OTHER => $fields['title'] ?? 'سایر',
        ];
        $employeeName = $payroll->employee->name ?? 'کارمند';
        $expenseTitle = ($titleMap[$paymentType] ?? 'پرداخت').' '
            .$employeeName.' - '
            .$payroll->payroll_year.'/'.$payroll->payroll_month;

        $expense = $this->createPayrollExpense(
            (int) $payroll->atelier_id,
            $userName,
            $amount,
            $expenseTitle,
            $fields
        );

        $payment = EmployeePayrollPayment::create([
            'atelier_id' => (int) $payroll->atelier_id,
            'payroll_id' => $payroll->id,
            'amount' => $amount,
            'payment_type' => $paymentType,
            'title' => $fields['title'] ?? null,
            'paid_by_user_id' => $actor->id,
            'expense_id' => $expense->id,
            'note' => $fields['note'] ?? null,
        ]);

        $payroll->syncStatus();

        return $payment;
    }

    /**
     * هر پرداخت حقوق/مساعده یک ردیف در هزینه‌ها می‌سازد.
     *
     * @param  array<string, mixed>  $fields
     */
    protected function createPayrollExpense(
        int $atelierId,
        string $userName,
        float $amount,
        string $title,
        array $fields
    ): Expense {
        $payload = [
            'user_name' => $userName,
            'date' => now()->format('Y-m-d'),
            'amount' => $amount,
            'title' => $title,
            'type' => 'جاری',
            'atelier_id' => $atelierId,
        ];

        if (Schema::hasColumn('expenses', 'shop_account_id')) {
            $payment = DocumentPaymentService::resolveOnCreate(
                $atelierId,
                $fields,
                $amount,
                'expenses'
            );

            // بدون حساب: باز هم سند هزینه ثبت شود تا در لیست هزینه‌ها دیده شود
            $explicitMethod = $fields['payment_method'] ?? null;
            $isDeferred = in_array($explicitMethod, [
                DocumentPaymentService::METHOD_CREDIT,
                DocumentPaymentService::METHOD_CHEQUE,
            ], true);
            if (! $isDeferred && empty($payment['shop_account_id']) && empty($fields['shop_account_id'])) {
                if (Schema::hasColumn('expenses', 'payment_status')) {
                    $payment['payment_method'] = DocumentPaymentService::METHOD_ACCOUNT;
                    $payment['payment_status'] = DocumentPaymentService::STATUS_PAID;
                    $payment['paid_at'] = now();
                }
            }

            $payload = array_merge($payload, $payment);
        }

        $chequePayload = [
            'cheque' => $fields['cheque'] ?? null,
            'cheque_id' => $fields['cheque_id'] ?? null,
            'payment_method' => $payload['payment_method'] ?? ($fields['payment_method'] ?? null),
        ];

        $expense = Expense::create($payload);
        DocumentPaymentService::attachChequeFromRequest($expense, $chequePayload, $userName);

        return $expense;
    }
}
