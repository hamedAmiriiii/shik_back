<?php

namespace App\Services;

use App\Models\AccountingVoucher;
use App\Models\Cheque;
use App\Models\Installment;
use App\Models\Purchase;
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

        $purchase->loadMissing(['installments', 'cheque']);
        AccountingReturnPoster::reverseForPurchase($purchase);
        foreach ($purchase->installments as $installment) {
            AccountingVoucherService::reversePostedIfAny(
                $atelierId,
                AccountingVoucher::SOURCE_INSTALLMENT_PAY,
                (int) $installment->id
            );
        }
        if ($purchase->cheque_id) {
            AccountingVoucherService::reversePostedIfAny(
                $atelierId,
                AccountingVoucher::SOURCE_CHEQUE_CLEAR,
                (int) $purchase->cheque_id
            );
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

    public static function postInstallmentPay(Installment $installment): ?AccountingVoucher
    {
        $installment->loadMissing('purchase');
        $purchase = $installment->purchase;
        if (! $purchase) {
            return null;
        }
        $atelierId = (int) $purchase->atelier_id;
        $amount = round((float) $installment->amount, 2);
        if ($atelierId <= 0 || $amount < 0.01 || ! AccountingLedger::ready()) {
            return null;
        }

        return AccountingVoucherService::post(
            $atelierId,
            self::eventDate($installment->paid_at),
            'وصول قسط فروش #'.$purchase->id,
            AccountingVoucher::SOURCE_INSTALLMENT_PAY,
            (int) $installment->id,
            [
                ['account_id' => AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_TILL), 'debit' => $amount, 'credit' => 0],
                ['account_id' => AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_AR), 'debit' => 0, 'credit' => $amount],
            ]
        );
    }

    public static function postDebtSettle(Purchase $purchase): ?AccountingVoucher
    {
        $atelierId = (int) $purchase->atelier_id;
        $amount = round(
            (float) $purchase->debt_settled_card_amount + (float) $purchase->debt_settled_cash_amount,
            2
        );
        if ($atelierId <= 0 || $amount < 0.01 || ! AccountingLedger::ready()) {
            return null;
        }

        return AccountingVoucherService::post(
            $atelierId,
            self::eventDate($purchase->debt_settled_at),
            'تسویه نسیه فروش #'.$purchase->id,
            AccountingVoucher::SOURCE_DEBT_SETTLE,
            (int) $purchase->id,
            [
                ['account_id' => AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_TILL), 'debit' => $amount, 'credit' => 0],
                ['account_id' => AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_AR), 'debit' => 0, 'credit' => $amount],
            ]
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

        return AccountingVoucherService::post(
            $atelierId,
            self::eventDate($cheque->cleared_at),
            $cheque->purchase_id ? 'وصول چک فروش #'.$cheque->purchase_id : 'وصول چک دریافتنی #'.$cheque->id,
            AccountingVoucher::SOURCE_CHEQUE_CLEAR,
            (int) $cheque->id,
            [
                ['account_id' => AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_TILL), 'debit' => $amount, 'credit' => 0],
                ['account_id' => AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_CHEQUE_RECEIVABLE), 'debit' => 0, 'credit' => $amount],
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
        $till = round((float) $purchase->cash_amount + (float) $purchase->card_amount, 2);
        $cheque = $purchase->isCheque() ? round($purchase->chequeAmount(), 2) : 0.0;
        $ar = 0.0;
        if ($purchase->isDebt() || $purchase->isInstallment()) {
            $ar = round(max(0, $sales - $discount - $credit - $till - $cheque), 2);
        }

        $left = round($till + $cheque + $ar + $discount + $credit, 2);
        $diff = round($sales - $left, 2);
        if (abs($diff) >= 0.01) {
            if ($purchase->isDebt() || $purchase->isInstallment()) {
                $ar = round(max(0, $ar + $diff), 2);
            } else {
                $till = round(max(0, $till + $diff), 2);
            }
        }

        $lines = [];
        self::push($lines, AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_TILL), $till, 0, 'نقد/کارت');
        self::push($lines, AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_CHEQUE_RECEIVABLE), $cheque, 0, 'چک دریافتنی');
        self::push($lines, AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_AR), $ar, 0, 'حساب دریافتنی');
        self::push($lines, AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_DISCOUNT), $discount, 0, 'تخفیف');
        self::push($lines, AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_LOYALTY), $credit, 0, 'اعتبار وفاداری');
        self::push($lines, AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_REVENUE), 0, $sales, 'درآمد فروش');

        $cogs = [
            ChartOfAccountsSeeder::CODE_INV_CATALOG => 0.0,
            ChartOfAccountsSeeder::CODE_INV_RAW => 0.0,
            ChartOfAccountsSeeder::CODE_INV_FINISHED => 0.0,
        ];
        foreach ($purchase->purchasedProducts as $item) {
            $cost = round((float) $item->purchase_price * (float) $item->quantity, 2);
            if ($cost < 0.01) {
                continue;
            }
            if ($item->produced_good_id) {
                $cogs[ChartOfAccountsSeeder::CODE_INV_FINISHED] += $cost;
            } elseif ($item->raw_material_id) {
                $cogs[ChartOfAccountsSeeder::CODE_INV_RAW] += $cost;
            } else {
                $cogs[ChartOfAccountsSeeder::CODE_INV_CATALOG] += $cost;
            }
        }

        $cogsTotal = round(array_sum($cogs), 2);
        if ($cogsTotal >= 0.01) {
            self::push($lines, AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_COGS), $cogsTotal, 0, 'بهای تمام‌شده');
            foreach ($cogs as $code => $amount) {
                self::push(
                    $lines,
                    AccountingLedger::accountId($atelierId, $code),
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
