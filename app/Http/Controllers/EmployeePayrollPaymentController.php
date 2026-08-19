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

        return response([
            'payroll_id' => $employeePayroll->id,
            'salary_amount' => (float) $employeePayroll->salary_amount,
            'total_paid' => $employeePayroll->totalPaid(),
            'remaining' => $employeePayroll->remaining(),
            'status' => $employeePayroll->status,
            'payments' => $payments,
        ], 200);
    }

    /**
     * ثبت پرداخت (بخشی از حقوق، مساعده، سایر)
     */
    public function store(Request $request, EmployeePayroll $employeePayroll)
    {
        $actor = $this->requireStaffShopUser($request);
        $this->assertModelBelongsToStaffAtelier($request, $employeePayroll);

        if ($employeePayroll->isPaid()) {
            return response()->json(['message' => 'حقوق این ماه کاملاً تسویه شده است.'], 422);
        }

        $fields = $request->validate([
            'amount' => 'required|numeric|min:1',
            'payment_type' => 'nullable|string|in:salary,advance,other',
            'title' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:2000',
        ]);

        $paymentType = $fields['payment_type'] ?? EmployeePayrollPayment::TYPE_SALARY;
        $amount = (float) $fields['amount'];

        // برای نوع salary بررسی سقف
        if ($paymentType === EmployeePayrollPayment::TYPE_SALARY) {
            $remaining = $employeePayroll->remaining();
            if ($amount > $remaining + 0.01) {
                return response()->json([
                    'message' => "مبلغ پرداخت ({$amount}) بیشتر از مانده حقوق ({$remaining}) است.",
                ], 422);
            }
        }

        $payment = null;

        DB::transaction(function () use ($employeePayroll, $actor, $fields, $paymentType, $amount, &$payment) {
            $locked = EmployeePayroll::query()
                ->where('id', $employeePayroll->id)
                ->lockForUpdate()
                ->with('employee:id,atelier_id,name,phone')
                ->first();

            if (! $locked) {
                abort(response()->json(['message' => 'فیش حقوقی یافت نشد.'], 404));
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
            $expenseTitle = ($titleMap[$paymentType] ?? 'پرداخت') . ' '
                . $locked->employee->name . ' - '
                . $locked->payroll_year . '/' . $locked->payroll_month;

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

        $employeePayroll->refresh();

        return response([
            'message' => 'پرداخت با موفقیت ثبت شد.',
            'payment' => $payment->load('paidBy:id,name,last_name'),
            'total_paid' => $employeePayroll->totalPaid(),
            'remaining' => $employeePayroll->remaining(),
            'status' => $employeePayroll->status,
        ], 201);
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

        return response([
            'message' => 'پرداخت حذف شد.',
            'total_paid' => $employeePayroll->totalPaid(),
            'remaining' => $employeePayroll->remaining(),
            'status' => $employeePayroll->status,
        ], 200);
    }
}
