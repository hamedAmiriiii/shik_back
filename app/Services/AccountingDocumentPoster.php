<?php

namespace App\Services;

use App\Models\AccountingVoucher;
use App\Models\DocumentPayment;
use App\Models\EmployeePayrollPayment;
use App\Models\Expense;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class AccountingDocumentPoster
{
    public static function syncInvoice(Invoice $invoice): ?AccountingVoucher
    {
        $invoice->loadMissing(['payments', 'rawMaterialLots']);

        return self::syncDocument(
            (int) $invoice->atelier_id,
            AccountingVoucher::SOURCE_INVOICE,
            (int) $invoice->id,
            function () use ($invoice) {
                return self::postInvoice($invoice);
            }
        );
    }

    public static function reverseInvoice(Invoice $invoice): void
    {
        self::reverseDocumentPayments($invoice);
        AccountingVoucherService::reversePostedIfAny(
            (int) $invoice->atelier_id,
            AccountingVoucher::SOURCE_INVOICE,
            (int) $invoice->id
        );
    }

    public static function syncExpense(Expense $expense, ?string $payrollType = null): ?AccountingVoucher
    {
        $expense->loadMissing(['payments']);

        return self::syncDocument(
            (int) $expense->atelier_id,
            AccountingVoucher::SOURCE_EXPENSE,
            (int) $expense->id,
            function () use ($expense, $payrollType) {
                return self::postExpense($expense, $payrollType);
            }
        );
    }

    public static function reverseExpense(Expense $expense): void
    {
        self::reverseDocumentPayments($expense);
        AccountingVoucherService::reversePostedIfAny(
            (int) $expense->atelier_id,
            AccountingVoucher::SOURCE_EXPENSE,
            (int) $expense->id
        );
    }

    public static function postPaymentSettle(DocumentPayment $payment, Model $document): ?AccountingVoucher
    {
        $atelierId = (int) $payment->atelier_id;
        $amount = round((float) $payment->amount, 2);
        $shopAccountId = (int) ($payment->shop_account_id ?? 0);
        if ($atelierId <= 0 || $amount < 0.01 || $shopAccountId <= 0 || ! AccountingLedger::ready()) {
            return null;
        }

        try {
            return AccountingVoucherService::post(
                $atelierId,
                self::documentDate($document),
                'تسویه نسیه سند #'.(int) $document->id,
                AccountingVoucher::SOURCE_DOCUMENT_PAYMENT,
                (int) $payment->id,
                [
                    [
                        'account_id' => AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_AP),
                        'debit' => $amount,
                        'credit' => 0,
                        'description' => 'بستن حساب پرداختنی',
                    ],
                    [
                        'account_id' => AccountingLedger::shopCashAccountId($atelierId, $shopAccountId),
                        'debit' => 0,
                        'credit' => $amount,
                        'description' => 'پرداخت از حساب',
                    ],
                ]
            );
        } catch (RuntimeException $e) {
            Log::error('سند تسویه ثبت نشد', [
                'payment_id' => $payment->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public static function postInvoice(Invoice $invoice): ?AccountingVoucher
    {
        $atelierId = (int) $invoice->atelier_id;
        $amount = round((float) $invoice->amount, 2);
        if ($atelierId <= 0 || (int) $invoice->id <= 0 || $amount < 0.01 || ! AccountingLedger::ready()) {
            return null;
        }

        $invoice->loadMissing(['payments', 'rawMaterialLots']);

        try {
            $lines = [];
            $raw = self::lotCost($invoice);
            $catalog = round(max(0, $amount - $raw), 2);
            AccountingLedger::push(
                $lines,
                AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_INV_RAW),
                $raw,
                0,
                'مواد اولیه'
            );
            AccountingLedger::push(
                $lines,
                AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_INV_CATALOG),
                $catalog,
                0,
                'خرید کالا'
            );
            self::appendPaymentCredits($lines, $atelierId, $invoice, $amount);

            if (count($lines) < 2) {
                return null;
            }

            return AccountingVoucherService::post(
                $atelierId,
                self::documentDate($invoice),
                'فاکتور خرید #'.$invoice->id.' — '.$invoice->title,
                AccountingVoucher::SOURCE_INVOICE,
                (int) $invoice->id,
                $lines
            );
        } catch (RuntimeException $e) {
            Log::error('سند فاکتور ثبت نشد', [
                'invoice_id' => $invoice->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public static function postExpense(Expense $expense, ?string $payrollType = null): ?AccountingVoucher
    {
        $atelierId = (int) $expense->atelier_id;
        $amount = round((float) $expense->amount, 2);
        if ($atelierId <= 0 || (int) $expense->id <= 0 || $amount < 0.01 || ! AccountingLedger::ready()) {
            return null;
        }

        $source = (string) ($expense->credit_source ?? '');
        if ($source === CustomerCreditExpenseService::SOURCE_LOYALTY
            || $source === CustomerCreditExpenseService::SOURCE_RETURN) {
            return null;
        }

        $expense->loadMissing(['payments']);

        try {
            $lines = [];
            if ($source === CustomerCreditExpenseService::SOURCE_MANUAL) {
                AccountingLedger::push(
                    $lines,
                    AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_LOYALTY),
                    $amount,
                    0,
                    'اعطای اعتبار دستی'
                );
                AccountingLedger::push(
                    $lines,
                    AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_AR),
                    0,
                    $amount,
                    'بدهی به مشتری'
                );
            } else {
                AccountingLedger::push(
                    $lines,
                    AccountingLedger::accountId($atelierId, self::expenseDebitCode($expense, $payrollType)),
                    $amount,
                    0,
                    $expense->title ?: 'هزینه'
                );
                self::appendPaymentCredits($lines, $atelierId, $expense, $amount);
            }

            if (count($lines) < 2) {
                return null;
            }

            return AccountingVoucherService::post(
                $atelierId,
                self::documentDate($expense),
                ($expense->title ?: 'هزینه').' #'.$expense->id,
                AccountingVoucher::SOURCE_EXPENSE,
                (int) $expense->id,
                $lines
            );
        } catch (RuntimeException $e) {
            Log::error('سند هزینه ثبت نشد', [
                'expense_id' => $expense->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * @param  callable(): ?AccountingVoucher  $poster
     */
    protected static function syncDocument(int $atelierId, string $sourceType, int $sourceId, callable $poster): ?AccountingVoucher
    {
        if ($atelierId <= 0 || $sourceId <= 0 || ! AccountingLedger::ready()) {
            return null;
        }

        AccountingVoucherService::reversePostedIfAny($atelierId, $sourceType, $sourceId);

        return $poster();
    }

    protected static function reverseDocumentPayments(Model $model): void
    {
        $atelierId = (int) $model->atelier_id;
        if ($atelierId <= 0 || ! AccountingLedger::ready() || ! Schema::hasTable('document_payments')) {
            return;
        }

        $model->loadMissing('payments');
        foreach ($model->payments as $payment) {
            AccountingVoucherService::reversePostedIfAny(
                $atelierId,
                AccountingVoucher::SOURCE_DOCUMENT_PAYMENT,
                (int) $payment->id
            );
        }
    }

    protected static function lotCost(Invoice $invoice): float
    {
        if (! Schema::hasColumn('raw_material_lots', 'invoice_id')) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($invoice->rawMaterialLots as $lot) {
            $total += round((float) $lot->quantity_kg * (float) $lot->price_per_kg, 2);
        }

        return round(min($total, (float) $invoice->amount), 2);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected static function appendPaymentCredits(array &$lines, int $atelierId, Model $model, float $amount): void
    {
        $credited = 0.0;
        if (Schema::hasTable('document_payments') && $model->relationLoaded('payments') && $model->payments->isNotEmpty()) {
            foreach ($model->payments as $payment) {
                $part = round((float) $payment->amount, 2);
                self::pushCreditForSplit($lines, $atelierId, (string) $payment->method, $part, $payment->shop_account_id);
                $credited += $part;
            }
            $gap = round($amount - $credited, 2);
            if (abs($gap) >= 0.01) {
                AccountingLedger::push(
                    $lines,
                    AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_AP),
                    0,
                    $gap,
                    'مانده پرداختنی'
                );
            }

            return;
        }

        $method = DocumentPaymentService::normalizeMethod($model->payment_method ?? null)
            ?: (($model->shop_account_id && DocumentPaymentService::isPaid($model))
                ? DocumentPaymentService::METHOD_ACCOUNT
                : DocumentPaymentService::METHOD_CREDIT);
        self::pushCreditForSplit($lines, $atelierId, $method, $amount, $model->shop_account_id ?? null);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected static function pushCreditForSplit(
        array &$lines,
        int $atelierId,
        string $method,
        float $amount,
        $shopAccountId
    ): void {
        $method = DocumentPaymentService::normalizeMethod($method) ?: $method;
        if ($method === DocumentPaymentService::METHOD_ACCOUNT && $shopAccountId) {
            AccountingLedger::push(
                $lines,
                AccountingLedger::shopCashAccountId($atelierId, (int) $shopAccountId),
                0,
                $amount,
                'پرداخت نقد'
            );

            return;
        }
        if ($method === DocumentPaymentService::METHOD_CHEQUE) {
            AccountingLedger::push(
                $lines,
                AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_CHEQUE_PAYABLE),
                0,
                $amount,
                'چک پرداختنی'
            );

            return;
        }

        AccountingLedger::push(
            $lines,
            AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_AP),
            0,
            $amount,
            'حساب پرداختنی'
        );
    }

    protected static function expenseDebitCode(Expense $expense, ?string $payrollType = null): string
    {
        if (($expense->type ?? '') === 'سرمایه') {
            return ChartOfAccountsSeeder::CODE_CAPEX;
        }

        $type = $payrollType;
        if ($type === null && Schema::hasTable('employee_payroll_payments')) {
            $type = EmployeePayrollPayment::query()
                ->where('expense_id', $expense->id)
                ->value('payment_type');
        }
        if (in_array($type, [EmployeePayrollPayment::TYPE_SALARY, EmployeePayrollPayment::TYPE_ADVANCE], true)) {
            return ChartOfAccountsSeeder::CODE_PAYROLL;
        }

        $title = (string) ($expense->title ?? '');
        if ($title !== '' && preg_match('/پرداخت حقوق|مساعده/u', $title)) {
            return ChartOfAccountsSeeder::CODE_PAYROLL;
        }

        return ChartOfAccountsSeeder::CODE_EXPENSE;
    }

    protected static function documentDate(Model $model): string
    {
        $raw = method_exists($model, 'getRawOriginal') ? $model->getRawOriginal('date') : null;

        return AccountingLedger::eventDate($raw ?: ($model->getAttributes()['date'] ?? null));
    }
}
