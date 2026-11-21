<?php

namespace App\Services;

use App\Models\AccountingVoucher;
use App\Models\Cheque;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\PurchaseDebtPayment;
use App\Models\ShopAccount;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AccountingSalePoster
{
    public static function post(Purchase $purchase): ?AccountingVoucher
    {
        $atelierId = (int) $purchase->atelier_id;
        if ($atelierId <= 0 || (int) $purchase->id <= 0 || ! AccountingLedger::ready()) {
            return null;
        }

        $purchase->loadMissing(['purchasedProducts', 'cheque']);

        try {
            $lines = self::saleLines($atelierId, $purchase);
            if (count($lines) < 2) {
                return null;
            }

            return AccountingVoucherService::post(
                $atelierId,
                self::purchaseDate($purchase),
                self::saleDescription($purchase),
                AccountingVoucher::SOURCE_PURCHASE,
                (int) $purchase->id,
                $lines
            );
        } catch (RuntimeException $e) {
            if ($e instanceof \Illuminate\Database\QueryException
                && AccountingVoucherService::isDeadlockException($e)) {
                throw $e;
            }
            Log::error('سند فروش ثبت نشد', [
                'purchase_id' => $purchase->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public static function reversePurchase(Purchase $purchase): void
    {
        $atelierId = (int) $purchase->atelier_id;
        if ($atelierId <= 0 || ! AccountingLedger::ready()) {
            return;
        }

        $with = ['installments', 'cheque'];
        if (Schema::hasTable('purchase_debt_payments')) {
            $with[] = 'debtPayments';
        }
        if (Schema::hasTable('installment_payments')) {
            $with[] = 'installmentPayments';
        }
        $purchase->loadMissing($with);
        AccountingReturnPoster::reverseForPurchase($purchase);
        foreach ($purchase->installments as $installment) {
            AccountingVoucherService::reversePostedIfAny(
                $atelierId,
                AccountingVoucher::SOURCE_INSTALLMENT_PAY,
                (int) $installment->id
            );
        }
        if (Schema::hasTable('installment_payments')) {
            foreach ($purchase->installmentPayments as $installmentPayment) {
                AccountingVoucherService::reversePostedIfAny(
                    $atelierId,
                    AccountingVoucher::SOURCE_INSTALLMENT_PAYMENT,
                    (int) $installmentPayment->id
                );
            }
        }
        if ($purchase->cheque_id) {
            AccountingVoucherService::reversePostedIfAny(
                $atelierId,
                AccountingVoucher::SOURCE_CHEQUE_CLEAR,
                (int) $purchase->cheque_id
            );
        }
        if (Schema::hasTable('purchase_debt_payments')) {
            foreach ($purchase->debtPayments as $payment) {
                AccountingVoucherService::reversePostedIfAny(
                    $atelierId,
                    AccountingVoucher::SOURCE_DEBT_PAYMENT,
                    (int) $payment->id
                );
            }
        }
        AccountingVoucherService::reversePostedIfAny(
            $atelierId,
            AccountingVoucher::SOURCE_DEBT_SETTLE,
            (int) $purchase->id
        );
        AccountingVoucherService::reversePostedIfAny(
            $atelierId,
            AccountingVoucher::SOURCE_PURCHASE,
            (int) $purchase->id
        );
    }

    public static function postInstallmentPay(
        Installment $installment,
        ?float $paidAmount = null,
        ?\App\Models\InstallmentPayment $payment = null
    ): ?AccountingVoucher {
        $installment->loadMissing('purchase');
        $purchase = $installment->purchase;
        if (! $purchase) {
            return null;
        }
        $atelierId = (int) $purchase->atelier_id;
        $amount = round(
            $paidAmount !== null
                ? $paidAmount
                : (float) ($payment?->amount ?? $installment->amount),
            2
        );
        if ($atelierId <= 0 || $amount < 0.01 || ! AccountingLedger::ready()) {
            return null;
        }

        $eventAt = $payment?->paid_at ?: ($installment->paid_at ?: now());
        $sourceType = $payment
            ? AccountingVoucher::SOURCE_INSTALLMENT_PAYMENT
            : AccountingVoucher::SOURCE_INSTALLMENT_PAY;
        $sourceId = $payment ? (int) $payment->id : (int) $installment->id;

        return AccountingVoucherService::post(
            $atelierId,
            self::eventDate($eventAt),
            'وصول قسط فروش #'.$purchase->id,
            $sourceType,
            $sourceId,
            [
                ['account_id' => AccountingLedger::mapped($atelierId, 'installment_pay.cash'), 'debit' => $amount, 'credit' => 0],
                ['account_id' => AccountingLedger::mapped($atelierId, 'installment_pay.ar'), 'debit' => 0, 'credit' => $amount],
            ]
        );
    }

    public static function postDebtSettle(Purchase $purchase): ?AccountingVoucher
    {
        $atelierId = (int) $purchase->atelier_id;
        $cash = round((float) $purchase->debt_settled_cash_amount, 2);
        $card = round((float) $purchase->debt_settled_card_amount, 2);
        $amount = round($cash + $card, 2);
        if ($atelierId <= 0 || $amount < 0.01 || ! AccountingLedger::ready()) {
            return null;
        }

        // مثل فروش: نقد→صندوق، کارت→کارتخوان؛ واریز به حساب بانکی فقط با تطبیق روزانه
        $lines = [];
        AccountingLedger::push($lines, AccountingLedger::mapped($atelierId, 'debt_settle.cash'), $cash, 0, 'وصول نقد نسیه');
        AccountingLedger::push($lines, AccountingLedger::mapped($atelierId, 'debt_settle.card'), $card, 0, 'وصول کارت نسیه');
        AccountingLedger::push($lines, AccountingLedger::mapped($atelierId, 'debt_settle.ar'), 0, $amount, 'بستن طلب مشتری');

        return AccountingVoucherService::post(
            $atelierId,
            self::eventDate($purchase->debt_settled_at),
            'تسویه نسیه فروش #'.$purchase->id,
            AccountingVoucher::SOURCE_DEBT_SETTLE,
            (int) $purchase->id,
            $lines
        );
    }

    public static function postDebtPayment(PurchaseDebtPayment $payment): ?AccountingVoucher
    {
        $payment->loadMissing('purchase');
        $purchase = $payment->purchase;
        if (! $purchase) {
            return null;
        }

        $atelierId = (int) $purchase->atelier_id;
        $cash = round((float) $payment->cash_amount, 2);
        $card = round((float) $payment->card_amount, 2);
        $amount = round($cash + $card, 2);
        if ($atelierId <= 0 || $amount < 0.01 || ! AccountingLedger::ready()) {
            return null;
        }

        // مثل فروش: نقد→صندوق، کارت→کارتخوان؛ حساب انتخاب‌شده فقط مقصد واریز بعد از تطبیق است
        $lines = [];
        AccountingLedger::push($lines, AccountingLedger::mapped($atelierId, 'debt_payment.cash'), $cash, 0, 'وصول نقد نسیه');
        AccountingLedger::push($lines, AccountingLedger::mapped($atelierId, 'debt_payment.card'), $card, 0, 'وصول کارت نسیه');
        AccountingLedger::push($lines, AccountingLedger::mapped($atelierId, 'debt_payment.ar'), 0, $amount, 'کاهش طلب مشتری');

        return AccountingVoucherService::post(
            $atelierId,
            self::eventDate($payment->paid_at),
            'پرداخت نسیه فروش #'.$purchase->id,
            AccountingVoucher::SOURCE_DEBT_PAYMENT,
            (int) $payment->id,
            $lines
        );
    }

    public static function postChequeClear(Cheque $cheque): ?AccountingVoucher
    {
        $atelierId = (int) $cheque->atelier_id;
        $amount = round((float) $cheque->amount, 2);
        if (
            $atelierId <= 0
            || $amount < 0.01
            || $cheque->type !== Cheque::TYPE_RECEIVED
            || ! AccountingLedger::ready()
        ) {
            return null;
        }

        // حساب مقصد انتخاب‌شده (بانک/تنخواه) همان لحظه شارژ می‌شود؛ فقط اگر مقصد صندوق باشد در صندوق می‌ماند.
        $debitAccountId = AccountingLedger::mapped($atelierId, 'cheque_clear_received.cash_fallback');
        $shopAccountId = (int) ($cheque->shop_account_id ?? 0);
        if ($shopAccountId > 0) {
            $shop = ShopAccount::query()->find($shopAccountId);
            if ($shop && (int) $shop->atelier_id === $atelierId && ! $shop->isTill()) {
                $debitAccountId = AccountingLedger::shopCashAccountId($atelierId, $shopAccountId);
            }
        }

        return AccountingVoucherService::post(
            $atelierId,
            self::eventDate($cheque->cleared_at),
            $cheque->purchase_id ? 'وصول چک فروش #'.$cheque->purchase_id : 'وصول چک دریافتنی #'.$cheque->id,
            AccountingVoucher::SOURCE_CHEQUE_CLEAR,
            (int) $cheque->id,
            [
                [
                    'account_id' => $debitAccountId,
                    'debit' => $amount,
                    'credit' => 0,
                    'description' => 'ورود وجه وصول چک',
                ],
                [
                    'account_id' => AccountingLedger::mapped($atelierId, 'cheque_clear_received.cheque_receivable'),
                    'debit' => 0,
                    'credit' => $amount,
                    'description' => 'بستن چک دریافتنی',
                ],
            ]
        );
    }

    public static function reverseChequeClear(Cheque $cheque): void
    {
        $atelierId = (int) $cheque->atelier_id;
        if ($atelierId <= 0 || ! AccountingLedger::ready()) {
            return;
        }

        AccountingVoucherService::reversePostedIfAny(
            $atelierId,
            AccountingVoucher::SOURCE_CHEQUE_CLEAR,
            (int) $cheque->id
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected static function saleLines(int $atelierId, Purchase $purchase): array
    {
        $sales = round($purchase->remainingLineSalesTotal(), 2);
        if ($sales <= 0) {
            $sales = round((float) $purchase->total_amount, 2);
        }
        if ($sales < 0.01) {
            return [];
        }

        $discount = round(ShopSalesReportService::discountGivenForPurchase($purchase), 2);
        $credit = round((float) $purchase->credit_used, 2);
        $cash = round((float) $purchase->cash_amount, 2);
        $card = round((float) $purchase->card_amount, 2);
        $cheque = $purchase->isCheque() ? round($purchase->chequeAmount(), 2) : 0.0;
        $ar = 0.0;
        if ($purchase->isDebt() || $purchase->isInstallment()) {
            $ar = round(max(0, $sales - $discount - $credit - $cash - $card - $cheque), 2);
        }

        $left = round($cash + $card + $cheque + $ar + $discount + $credit, 2);
        $diff = round($sales - $left, 2);
        if (abs($diff) >= 0.01) {
            if ($purchase->isDebt() || $purchase->isInstallment()) {
                $ar = round(max(0, $ar + $diff), 2);
            } elseif ($cash >= 0.01 || $card < 0.01) {
                $cash = round(max(0, $cash + $diff), 2);
            } else {
                $card = round(max(0, $card + $diff), 2);
            }
        }

        $vatSplit = SaleVatService::splitGross($atelierId, $sales);
        $revenue = $vatSplit['net'];
        $vatOutput = $vatSplit['vat'];

        $lines = [];
        self::push($lines, AccountingLedger::mapped($atelierId, 'purchase.cash'), $cash, 0, 'نقد صندوق');
        self::push($lines, AccountingLedger::mapped($atelierId, 'purchase.card'), $card, 0, 'کارتخوان در راه');
        self::push($lines, AccountingLedger::mapped($atelierId, 'purchase.cheque_receivable'), $cheque, 0, 'چک دریافتنی');
        self::push($lines, AccountingLedger::mapped($atelierId, 'purchase.ar'), $ar, 0, 'حساب دریافتنی');
        self::push($lines, AccountingLedger::mapped($atelierId, 'purchase.discount'), $discount, 0, 'تخفیف');
        self::push($lines, AccountingLedger::mapped($atelierId, 'purchase.loyalty'), $credit, 0, 'اعتبار وفاداری');
        self::push($lines, AccountingLedger::mapped($atelierId, 'purchase.revenue'), 0, $revenue, 'درآمد فروش');
        self::push($lines, AccountingLedger::mapped($atelierId, 'purchase.vat_output'), 0, $vatOutput, 'ارزش افزوده فروش');

        $cogs = [
            'purchase.cogs_inv_catalog' => 0.0,
            'purchase.cogs_inv_raw' => 0.0,
            'purchase.cogs_inv_finished' => 0.0,
        ];
        foreach ($purchase->purchasedProducts as $item) {
            $cost = round((float) $item->purchase_price * (float) $item->quantity, 2);
            if ($cost < 0.01) {
                continue;
            }
            if ($item->produced_good_id) {
                $cogs['purchase.cogs_inv_finished'] += $cost;
            } elseif ($item->raw_material_id) {
                $cogs['purchase.cogs_inv_raw'] += $cost;
            } else {
                $cogs['purchase.cogs_inv_catalog'] += $cost;
            }
        }

        $cogsTotal = round(array_sum($cogs), 2);
        if ($cogsTotal >= 0.01) {
            self::push($lines, AccountingLedger::mapped($atelierId, 'purchase.cogs'), $cogsTotal, 0, 'بهای تمام‌شده');
            foreach ($cogs as $eventKey => $amount) {
                self::push(
                    $lines,
                    AccountingLedger::mapped($atelierId, $eventKey),
                    0,
                    round($amount, 2),
                    'خروج موجودی'
                );
            }
        }

        return $lines;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected static function push(array &$lines, int $accountId, float $debit, float $credit, string $description): void
    {
        $debit = round($debit, 2);
        $credit = round($credit, 2);
        if ($debit < 0.01 && $credit < 0.01) {
            return;
        }
        $lines[] = [
            'account_id' => $accountId,
            'debit' => $debit,
            'credit' => $credit,
            'description' => $description,
        ];
    }

    protected static function saleDescription(Purchase $purchase): string
    {
        $kind = 'نقد';
        if ($purchase->isInstallment()) {
            $kind = 'اقساط — طلب کامل';
        } elseif ($purchase->isDebt()) {
            $kind = 'نسیه';
        } elseif ($purchase->isCheque()) {
            $kind = 'چک';
        }

        return 'فروش #'.$purchase->id.' ('.$kind.')';
    }

    protected static function purchaseDate(Purchase $purchase): string
    {
        $raw = $purchase->getRawOriginal('created_at');

        return self::eventDate($raw);
    }

    protected static function eventDate($value): string
    {
        if (! $value) {
            return Carbon::now('Asia/Tehran')->toDateString();
        }

        return Carbon::parse($value)->timezone('Asia/Tehran')->toDateString();
    }
}
