<?php

namespace App\Http\Controllers;

use App\Models\EmployeePayroll;
use App\Models\EmployeePayrollItem;
use App\Services\PayrollSalaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class EmployeePayrollItemController extends Controller
{
    public function index(Request $request, EmployeePayroll $employeePayroll)
    {
        $this->assertModelBelongsToStaffAtelier($request, $employeePayroll);

        if (! Schema::hasTable('employee_payroll_items')) {
            return response(['data' => []], 200);
        }

        return response([
            'data' => $employeePayroll->items()->get(),
            'preset_types' => $this->presets(),
        ], 200);
    }

    public function store(Request $request, EmployeePayroll $employeePayroll)
    {
        $this->assertModelBelongsToStaffAtelier($request, $employeePayroll);

        if (! Schema::hasTable('employee_payroll_items')) {
            return response()->json(['message' => 'جدول ردیف‌های فیش وجود ندارد.'], 503);
        }

        if (! $employeePayroll->canRecalculateSalary()) {
            return response()->json([
                'message' => 'پس از پرداخت حقوق، ردیف‌های فیش قابل تغییر نیست.',
            ], 422);
        }

        $fields = $request->validate([
            'item_type' => 'required|string|in:'.implode(',', EmployeePayrollItem::types()),
            'title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:2000',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $item = $employeePayroll->items()->create([
            'atelier_id' => $employeePayroll->atelier_id,
            'item_type' => $fields['item_type'],
            'title' => $fields['title'],
            'amount' => abs((float) $fields['amount']),
            'is_auto' => false,
            'sort_order' => (int) ($fields['sort_order'] ?? 0),
            'note' => $fields['note'] ?? null,
        ]);

        $payroll = app(PayrollSalaryService::class)->recomputePayrollTotals($employeePayroll);

        return response([
            'message' => 'ردیف فیش اضافه شد.',
            'item' => $item,
            'payroll' => array_merge($payroll->toArray(), $payroll->paymentSummary()),
        ], 201);
    }

    public function update(Request $request, EmployeePayroll $employeePayroll, EmployeePayrollItem $item)
    {
        $this->assertModelBelongsToStaffAtelier($request, $employeePayroll);

        if ((int) $item->payroll_id !== (int) $employeePayroll->id) {
            return response()->json(['message' => 'ردیف متعلق به این فیش نیست.'], 422);
        }

        if (! $employeePayroll->canRecalculateSalary()) {
            return response()->json([
                'message' => 'پس از پرداخت حقوق، ردیف‌های فیش قابل تغییر نیست.',
            ], 422);
        }

        $fields = $request->validate([
            'item_type' => 'sometimes|required|string|in:'.implode(',', EmployeePayrollItem::types()),
            'title' => 'sometimes|required|string|max:255',
            'amount' => 'sometimes|required|numeric|min:0',
            'note' => 'nullable|string|max:2000',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        if (array_key_exists('amount', $fields)) {
            $fields['amount'] = abs((float) $fields['amount']);
        }

        $item->update($fields);

        $payroll = app(PayrollSalaryService::class)->recomputePayrollTotals($employeePayroll);

        return response([
            'message' => 'ردیف فیش به‌روزرسانی شد.',
            'item' => $item->fresh(),
            'payroll' => array_merge($payroll->toArray(), $payroll->paymentSummary()),
        ], 200);
    }

    public function destroy(Request $request, EmployeePayroll $employeePayroll, EmployeePayrollItem $item)
    {
        $this->assertModelBelongsToStaffAtelier($request, $employeePayroll);

        if ((int) $item->payroll_id !== (int) $employeePayroll->id) {
            return response()->json(['message' => 'ردیف متعلق به این فیش نیست.'], 422);
        }

        if (! $employeePayroll->canRecalculateSalary()) {
            return response()->json([
                'message' => 'پس از پرداخت حقوق، ردیف‌های فیش قابل تغییر نیست.',
            ], 422);
        }

        $item->delete();
        $payroll = app(PayrollSalaryService::class)->recomputePayrollTotals($employeePayroll);

        return response([
            'message' => 'ردیف حذف شد.',
            'payroll' => array_merge($payroll->toArray(), $payroll->paymentSummary()),
        ], 200);
    }

    /**
     * @return array<int, array{type: string, title: string}>
     */
    protected function presets(): array
    {
        return [
            ['type' => EmployeePayrollItem::TYPE_BONUS, 'title' => 'پاداش'],
            ['type' => EmployeePayrollItem::TYPE_ALLOWANCE, 'title' => 'بن / حق خواربار'],
            ['type' => EmployeePayrollItem::TYPE_ALLOWANCE, 'title' => 'حق اولاد'],
            ['type' => EmployeePayrollItem::TYPE_DEDUCTION, 'title' => 'جریمه / غیبت مازاد'],
            ['type' => EmployeePayrollItem::TYPE_TAX, 'title' => 'مالیات حقوق'],
            ['type' => EmployeePayrollItem::TYPE_INSURANCE, 'title' => 'بیمه سهم کارمند'],
        ];
    }
}
