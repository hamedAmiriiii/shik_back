<?php

namespace App\Http\Controllers;

use App\Models\EmployeePayroll;
use App\Models\EmployeePayrollPayment;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeePayrollPaymentController extends Controller
{
    /**
     * لیست پرداخت‌های یک فیش حقوقی
     */
    public function index(Request $request, EmployeePayroll $employeePayroll)
    {
        $this->assertModelBelongsToStaffAtelier($request, $employeePayroll);

        $payments = $employeePayroll->payments()
            ->with('paidBy:id,name,last_name')
            ->orderBy('created_at')
            ->get();

        return response(array_merge([
            'payroll_id' => $employeePayroll->id,
            'payments' => $payments,
        ], $employeePayroll->paymentSummary()), 200);
    }

    /**
     * ثبت پرداخت (بخشی از حقوق، مساعده، سایر)
     *
     * مساعده می‌تواند از مانده/حقوق بیشتر باشد و بعداً از حقوق محاسبه‌شده کسر می‌شود.
     * پرداخت نوع salary فقط تا سقف مانده (پس از کسر مساعده) مجاز است.
     */
    public function store(Request $request, EmployeePayroll $employeePayroll)
    {
        $actor = $this->requireStaffShopUser($request);
        $this->assertModelBelongsToStaffAtelier($request, $employeePayroll);

        $fields = $request->validate([
            'amount' => 'required|numeric|min:1',
            'payment_type' => 'nullable|string|in:salary,advance,other',
            'title' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:2000',
        ]);

        $paymentType = $fields['payment_type'] ?? EmployeePayrollPayment::TYPE_SALARY;
        $amount = (float) $fields['amount'];

        // پرداخت حقوق فقط وقتی مانده‌ای بعد از مساعده باقی مانده باشد
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

                // پرداخت حقوق: سقف = مانده پس از کسر مساعده و پرداخت‌های قبلی
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

                $userName = trim(($actor->name ?? '').' '.($actor->last_name ?? ''));
                if ($userName === '') {
                    $userName = 'کاربر سیستم';
                }

                $titleMap = [
                    EmployeePayrollPayment::TYPE_SALARY => 'پرداخت حقوق',
                    EmployeePayrollPayment::TYPE_ADVANCE => 'مساعده',
                    EmployeePayrollPayment::TYPE_OTHER => $fields['title'] ?? 'سایر',
                ];
                $expenseTitle = ($titleMap[$paymentType] ?? 'پرداخت').' '
                    .$locked->employee->name.' - '
                    .$locked->payroll_year.'/'.$locked->payroll_month;

                $expense = Expense::create([
                    'user_name' => $userName,
                    'date' => now()->format('Y-m-d'),
                    'amount' => $amount,
                    'title' => $expenseTitle,
                    'type' => 'جاری',
                    'atelier_id' => (int) $locked->atelier_id,
                ]);

                $payment = EmployeePayrollPayment::create([
                    'atelier_id' => (int) $locked->atelier_id,
                    'payroll_id' => $locked->id,
                    'amount' => $amount,
                    'payment_type' => $paymentType,
                    'title' => $fields['title'] ?? null,
                    'paid_by_user_id' => $actor->id,
                    'expense_id' => $expense->id,
                    'note' => $fields['note'] ?? null,
                ]);

                $locked->syncStatus();
            });
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        }

        $employeePayroll->refresh();

        return response(array_merge([
            'message' => $paymentType === EmployeePayrollPayment::TYPE_ADVANCE
                ? 'مساعده ثبت شد و از حقوق محاسبه‌شده کسر خواهد شد.'
                : 'پرداخت با موفقیت ثبت شد.',
            'payment' => $payment->load('paidBy:id,name,last_name'),
        ], $employeePayroll->paymentSummary()), 201);
    }

    /**
     * حذف پرداخت (فقط آخرین پرداخت و فقط اگر حقوق هنوز کاملاً تسویه نشده)
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
}
