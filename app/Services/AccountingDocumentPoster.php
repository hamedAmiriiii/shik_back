<?php

namespace App\Services;

use App\Models\AccountingAccount;
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
        // اگر ردیف به فاکتور نسیه اضافه شده و amount عوض شده، سهم نسیه را هم‌تراز کن
        DocumentPaymentService::alignSplitsToDocumentAmount($invoice);
        $invoice->loadMissing(['payments', 'rawMaterialLots']);

        $main = self::syncDocument(
            (int) $invoice->atelier_id,
            AccountingVoucher::SOURCE_INVOICE,
            (int) $invoice->id,
            function () use ($invoice) {
                return self::postInvoice($invoice->fresh(['payments', 'rawMaterialLots']));
            },
            $invoice
        );
        self::syncVatVoucher($invoice);

        return $main;
    }

    public static function reverseInvoice(Invoice $invoice): void
    {
        self::reverseDocumentPayments($invoice);
        AccountingVoucherService::reversePostedIfAny(
            (int) $invoice->atelier_id,
            AccountingVoucher::SOURCE_INVOICE_VAT,
            (int) $invoice->id
        );
        AccountingVoucherService::reversePostedIfAny(
            (int) $invoice->atelier_id,
            AccountingVoucher::SOURCE_INVOICE,
            (int) $invoice->id
        );
    }

    public static function syncExpense(Expense $expense, ?string $payrollType = null): ?AccountingVoucher
    {
        $expense->loadMissing(['payments']);

        $main = self::syncDocument(
            (int) $expense->atelier_id,
            AccountingVoucher::SOURCE_EXPENSE,
            (int) $expense->id,
            function () use ($expense, $payrollType) {
                return self::postExpense($expense, $payrollType);
            },
            $expense
        );
        self::syncVatVoucher($expense);

        return $main;
    }

    public static function reverseExpense(Expense $expense): void
    {
        self::reverseDocumentPayments($expense);
        AccountingVoucherService::reversePostedIfAny(
            (int) $expense->atelier_id,
            AccountingVoucher::SOURCE_EXPENSE_VAT,
            (int) $expense->id
        );
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
                        'account_id' => AccountingLedger::mapped($atelierId, 'document_payment.ap'),
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
        $payable = PurchaseVatService::payableAmount($invoice);
        if ($atelierId <= 0 || (int) $invoice->id <= 0 || $payable < 0.01 || ! AccountingLedger::ready()) {
            return null;
        }

        $invoice->loadMissing(['payments', 'rawMaterialLots']);

        try {
            $lines = [];
            // مبلغ پایه + ارزش افزوده موقتاً به موجودی می‌رود؛ سند VAT جداگانه مالیات را به ۱۱۵۰۱ منتقل می‌کند
            $raw = self::lotCost($invoice);
            $catalog = round(max(0, $payable - $raw), 2);
            AccountingLedger::push(
                $lines,
                AccountingLedger::mapped($atelierId, 'invoice.inv_raw'),
                $raw,
                0,
                'مواد اولیه'
            );
            AccountingLedger::push(
                $lines,
                AccountingLedger::mapped($atelierId, 'invoice.inv_catalog'),
                $catalog,
                0,
                'خرید کالا'
            );
            self::appendPaymentCredits($lines, $atelierId, $invoice, $payable);

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
        $base = round((float) $expense->amount, 2);
        $payable = PurchaseVatService::payableAmount($expense);
        if ($atelierId <= 0 || (int) $expense->id <= 0 || $payable < 0.01 || ! AccountingLedger::ready()) {
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
                    AccountingLedger::mapped($atelierId, 'expense.manual_credit_grant.loyalty'),
                    $base,
                    0,
                    'اعطای اعتبار دستی'
                );
                AccountingLedger::push(
                    $lines,
                    AccountingLedger::mapped($atelierId, 'expense.manual_credit_grant.ar'),
                    0,
                    $base,
                    'بدهی به مشتری'
                );
            } else {
                AccountingLedger::push(
                    $lines,
                    self::expenseDebitAccountId($expense, $payrollType),
                    $payable,
                    0,
                    $expense->title ?: 'هزینه'
                );
                self::appendPaymentCredits($lines, $atelierId, $expense, $payable);
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
     * سند جدا برای ارزش افزوده خرید: بدهکار ۱۱۵۰۱ / بستانکار موجودی یا هزینه.
     *
     * @param  Invoice|Expense  $document
     */
    protected static function syncVatVoucher(Model $document): void
    {
        if (! ($document instanceof Invoice || $document instanceof Expense)) {
            return;
        }

        $atelierId = (int) $document->atelier_id;
        $sourceType = $document instanceof Invoice
            ? AccountingVoucher::SOURCE_INVOICE_VAT
            : AccountingVoucher::SOURCE_EXPENSE_VAT;
        $sourceId = (int) $document->id;

        if ($atelierId <= 0 || $sourceId <= 0 || ! AccountingLedger::ready()) {
            return;
        }

        AccountingVoucherService::reversePostedIfAny($atelierId, $sourceType, $sourceId);

        $vat = PurchaseVatService::vatAmount($document);
        if ($vat < 0.01) {
            return;
        }

        try {
            $creditAccountId = $document instanceof Invoice
                ? AccountingLedger::mapped($atelierId, 'invoice_vat.credit_inventory')
                : self::expenseDebitAccountId($document, null);
            $vatEventKey = $document instanceof Invoice ? 'invoice_vat.vat_input' : 'expense_vat.vat_input';

            AccountingVoucherService::post(
                $atelierId,
                self::documentDate($document),
                'ارزش افزوده '.($document instanceof Invoice ? 'فاکتور' : 'هزینه').' #'.$document->id,
                $sourceType,
                $sourceId,
                [
                    [
                        'account_id' => AccountingLedger::mapped($atelierId, $vatEventKey),
                        'debit' => $vat,
                        'credit' => 0,
                        'description' => 'ارزش افزوده خرید',
                    ],
                    [
                        'account_id' => $creditAccountId,
                        'debit' => 0,
                        'credit' => $vat,
                        'description' => 'جداسازی ارزش افزوده از '.($document instanceof Invoice ? 'موجودی' : 'هزینه'),
                    ],
                ]
            );
        } catch (RuntimeException $e) {
            Log::error('سند ارزش افزوده ثبت نشد', [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * @param  callable(): ?AccountingVoucher  $poster
     */
    protected static function syncDocument(
        int $atelierId,
        string $sourceType,
        int $sourceId,
        callable $poster,
        ?Model $document = null
    ): ?AccountingVoucher {
        if ($atelierId <= 0 || $sourceId <= 0 || ! AccountingLedger::ready()) {
            return null;
        }

        if ($document) {
            self::reverseDocumentPayments($document);
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
            $model->loadMissing('payments.cheque');
            foreach ($model->payments as $payment) {
                $part = round((float) $payment->amount, 2);
                if ($part < 0.01) {
                    continue;
                }
                $method = DocumentPaymentService::normalizeMethod((string) $payment->method) ?: (string) $payment->method;

                // نسیهٔ تسویه‌شده با ظهرنویسی/اعتبار جداگانه سند خورده؛ دوباره در فاکتور ننویس
                if ($method === DocumentPaymentService::METHOD_ADVANCE) {
                    continue;
                }
                // چک دریافتی ظهرنویسی‌شده در SOURCE_CHEQUE_TRANSFER ثبت شده
                if ($method === DocumentPaymentService::METHOD_CHEQUE) {
                    $cheque = $payment->cheque;
                    if ($cheque && $cheque->type === \App\Models\Cheque::TYPE_RECEIVED) {
                        continue;
                    }
                }
                // نسیهٔ تسویه‌شده بدون حساب = جایگزین شده با چک/اعتبار
                $isExpense = $model instanceof Expense;
                if ($method === DocumentPaymentService::METHOD_CREDIT && $payment->settled) {
                    if ($payment->shop_account_id) {
                        self::pushCreditForSplit(
                            $lines,
                            $atelierId,
                            DocumentPaymentService::METHOD_ACCOUNT,
                            $part,
                            $payment->shop_account_id,
                            $isExpense
                        );
                        $credited += $part;
                    }
                    continue;
                }

                self::pushCreditForSplit($lines, $atelierId, $method, $part, $payment->shop_account_id, $isExpense);
                $credited += $part;
            }
            $gap = round($amount - $credited, 2);
            if (abs($gap) >= 0.01) {
                AccountingLedger::push(
                    $lines,
                    AccountingLedger::mapped($atelierId, $model instanceof Expense ? 'expense.ap' : 'invoice.ap'),
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
        self::pushCreditForSplit(
            $lines,
            $atelierId,
            $method,
            $amount,
            $model->shop_account_id ?? null,
            $model instanceof Expense
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected static function pushCreditForSplit(
        array &$lines,
        int $atelierId,
        string $method,
        float $amount,
        $shopAccountId,
        bool $forExpense = false
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
                AccountingLedger::mapped($atelierId, $forExpense ? 'expense.cheque_payable' : 'invoice.cheque_payable'),
                0,
                $amount,
                'چک پرداختنی'
            );

            return;
        }

        AccountingLedger::push(
            $lines,
            AccountingLedger::mapped($atelierId, $forExpense ? 'expense.ap' : 'invoice.ap'),
            0,
            $amount,
            'حساب پرداختنی'
        );
    }

    protected static function expenseDebitAccountId(Expense $expense, ?string $payrollType = null): int
    {
        $atelierId = (int) $expense->atelier_id;
        $linkedId = (int) ($expense->accounting_account_id ?? 0);
        if ($linkedId > 0 && Schema::hasColumn('expenses', 'accounting_account_id')) {
            $account = AccountingAccount::query()
                ->forAtelier($atelierId)
                ->where('id', $linkedId)
                ->first();
            if ($account && $account->is_active) {
                $code = (string) $account->code;
                if ($code === ChartOfAccountsSeeder::CODE_LOYALTY
                    || $code === ChartOfAccountsSeeder::CODE_INV_CATALOG
                    || trim((string) $account->name) === 'خرید کالا') {
                    // fallback
                } elseif ($account->kind === AccountingAccount::KIND_EXPENSE || strpos($code, '121') === 0) {
                    return (int) $account->id;
                }
            }
        }

        return AccountingLedger::mapped($atelierId, self::expenseDebitEventKey($expense, $payrollType));
    }

    protected static function expenseDebitEventKey(Expense $expense, ?string $payrollType = null): string
    {
        if (($expense->type ?? '') === 'سرمایه') {
            return 'expense.debit_capex';
        }

        $type = $payrollType;
        if ($type === null && Schema::hasTable('employee_payroll_payments')) {
            $type = EmployeePayrollPayment::query()
                ->where('expense_id', $expense->id)
                ->value('payment_type');
        }
        if (in_array($type, [EmployeePayrollPayment::TYPE_SALARY, EmployeePayrollPayment::TYPE_ADVANCE], true)) {
            return 'expense.debit_payroll';
        }

        $title = (string) ($expense->title ?? '');
        if ($title !== '' && preg_match('/پرداخت حقوق|مساعده/u', $title)) {
            return 'expense.debit_payroll';
        }

        return 'expense.debit_opex';
    }

    protected static function documentDate(Model $model): string
    {
        $raw = method_exists($model, 'getRawOriginal') ? $model->getRawOriginal('date') : null;

        return AccountingLedger::eventDate($raw ?: ($model->getAttributes()['date'] ?? null));
    }
}
