<?php

namespace App\Services;

use App\Models\Installment;
use App\Models\Purchase;
use App\Models\ReturnedProduct;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class ShopSalesReportService
{
    /**
     * فروش، سود و وصول در بازه (تایم‌زون تهران) — بر اساس اقلام باقی‌مانده فاکتور.
     *
     * @return array<string, float>
     */
    public static function salesAndProfitForRange(
        int $atelierId,
        Carbon $startDate,
        Carbon $endDate
    ): array {
        $startString = $startDate->copy()->setTimezone('Asia/Tehran')->format('Y-m-d H:i:s');
        $endString = $endDate->copy()->setTimezone('Asia/Tehran')->format('Y-m-d H:i:s');

        $purchases = Purchase::with(['purchasedProducts.product', 'installments'])
            ->forAtelier($atelierId)
            ->whereBetween('created_at', [$startString, $endString])
            ->get();

        $totalSales = 0.0;
        $totalPurchase = 0.0;
        $creditEarnedFromPurchases = 0.0;
        $cardAmount = 0.0;
        $cashAmount = 0.0;
        $creditUsedTotal = 0.0;
        $uncollectedFromPeriodSales = 0.0;
        $discountGiven = 0.0;

        foreach ($purchases as $purchase) {
            $lineSales = $purchase->remainingLineSalesTotal();
            if ($lineSales <= 0) {
                continue;
            }

            $discountGiven += self::discountGivenForPurchase($purchase);

            $lineCost = $purchase->remainingLinePurchaseCost();

            if ($purchase->isInstallment()) {
                $totalSales += (float) $purchase->paid_amount + (float) $purchase->credit_used;
            } elseif ($purchase->isDebt()) {
                $totalSales += $lineSales;
            } else {
                // همان مبلغ فاکتور (total_amount) — هم‌خوان با card+cash در جدول purchases
                $totalSales += (float) $purchase->total_amount;
            }

            $totalPurchase += $lineCost;
            $creditEarnedFromPurchases += (float) $purchase->credit_earned;
            $creditUsedTotal += (float) $purchase->credit_used;

            [$card, $cash] = self::settlementForPurchase($purchase, $lineSales);
            $cardAmount += $card;
            $cashAmount += $cash;

            if ($purchase->isInstallment()) {
                $uncollectedFromPeriodSales += (float) $purchase->installments
                    ->where('is_paid', false)
                    ->sum('amount');
            }
        }

        $openDebts = self::openDebtsAsOf($atelierId, $endDate);

        $installmentsCollected = self::installmentsCollectedInRange($atelierId, $startString, $endString);
        $debtsCollected = self::debtsCollectedInRange($atelierId, $startString, $endString);
        $cashAndCardTotal = round($cardAmount + $cashAmount, 2);
        $settlementTotal = round($cashAndCardTotal + $creditUsedTotal + $installmentsCollected + $debtsCollected, 2);
        $totalCollected = $cashAndCardTotal + $installmentsCollected + $debtsCollected;

        $returnedProducts = ReturnedProduct::with('product')
            ->forAtelier($atelierId)
            ->whereBetween('created_at', [$startString, $endString])
            ->get();

        [$totalReturns, $totalReturnsPurchase] = self::sumReturnedProducts($returnedProducts);

        $netSales = round($totalSales - $totalReturns, 2);
        $netPurchase = round($totalPurchase - $totalReturnsPurchase, 2);

        $manualCreditGranted = UserCreditGrantService::sumManualGrantsInRange(
            $atelierId,
            $startString,
            $endString
        );

        $totalCreditGranted = $creditEarnedFromPurchases + $manualCreditGranted;
        $totalProfit = round($netSales - $netPurchase - $creditUsedTotal, 2);

        return [
            'sales' => (float) $netSales,
            'profit' => (float) $totalProfit,
            'returns' => (float) $totalReturns,
            'gross_sales' => (float) round($totalSales, 2),
            'credit_earned_from_purchases' => (float) $creditEarnedFromPurchases,
            'manual_credit_granted' => (float) $manualCreditGranted,
            'total_credit_granted' => (float) $totalCreditGranted,
            'net_purchase' => (float) $netPurchase,
            'card_amount' => (float) round($cardAmount, 2),
            'cash_amount' => (float) round($cashAmount, 2),
            'cash_and_card_total' => (float) $cashAndCardTotal,
            'installments_collected' => (float) $installmentsCollected,
            'debts_collected' => (float) $debtsCollected,
            'total_collected' => (float) round($totalCollected, 2),
            'uncollected_installments' => (float) $uncollectedFromPeriodSales,
            'uncollected_debts' => (float) round($openDebts, 2),
            'open_debt' => (float) round($openDebts, 2),
            'credit_used_total' => (float) round($creditUsedTotal, 2),
            'settlement_total' => (float) $settlementTotal,
            'discount_given' => (float) round($discountGiven, 2),
        ];
    }

    /**
     * تخفیف داده‌شده روی یک فاکتور (مبلغ ثبت‌شده یا اختلاف جمع خطوط با مبلغ نهایی فاکتور).
     */
    public static function discountGivenForPurchase(Purchase $purchase): float
    {
        if (Schema::hasColumn('purchases', 'discount_amount')
            && (float) $purchase->discount_amount > 0) {
            return (float) $purchase->discount_amount;
        }

        $lineTotal = $purchase->remainingLineSalesTotal();
        if ($lineTotal <= 0) {
            return 0.0;
        }

        if ($purchase->isInstallment()) {
            return 0.0;
        }

        // جمع خطوط − مبلغ فاکتور = تخفیف (اعتبار مصرف‌شده جداگانه ثبت می‌شود)
        return max(0, round($lineTotal - (float) $purchase->total_amount, 2));
    }

    /**
     * خلاصهٔ حساب‌های روز برای تطبیق (فروش، نقد، کارت، اقساط، جمع وصول، تخفیف).
     *
     * @param  array<string, float>  $metrics
     * @return array<string, float>
     */
    public static function accountsBreakdown(array $metrics): array
    {
        return [
            'total_sales' => (float) ($metrics['sales'] ?? 0),
            'cash_amount' => (float) ($metrics['cash_amount'] ?? 0),
            'card_amount' => (float) ($metrics['card_amount'] ?? 0),
            'installments_collected' => (float) ($metrics['installments_collected'] ?? 0),
            'debts_collected' => (float) ($metrics['debts_collected'] ?? 0),
            'total_collected' => (float) ($metrics['total_collected'] ?? 0),
            'discount_given' => (float) ($metrics['discount_given'] ?? 0),
            'credit_used_total' => (float) ($metrics['credit_used_total'] ?? 0),
            'settlement_total' => (float) ($metrics['settlement_total'] ?? 0),
            'uncollected_installments' => (float) ($metrics['uncollected_installments'] ?? 0),
            'uncollected_debts' => (float) ($metrics['uncollected_debts'] ?? 0),
            'open_debt' => (float) ($metrics['open_debt'] ?? $metrics['uncollected_debts'] ?? 0),
        ];
    }

    /**
     * نقد/کارت قابل‌قبول برای فاکتور (با سقف بر اساس اقلام باقی‌مانده).
     *
     * @return array{0: float, 1: float}
     */
    public static function settlementForPurchase(Purchase $purchase, float $lineSales): array
    {
        $card = (float) $purchase->card_amount;
        $cash = (float) $purchase->cash_amount;

        if ($purchase->isInstallment()) {
            return [$card, $cash];
        }

        if ($purchase->isDebt()) {
            return [0.0, 0.0];
        }

        $payable = max(0, round(
            $lineSales - (float) $purchase->discount_amount - (float) $purchase->credit_used,
            2
        ));
        $settlement = $card + $cash;

        if ($settlement > $payable + 0.02 && $settlement > 0) {
            $ratio = $payable / $settlement;
            $card = round($card * $ratio, 2);
            $cash = round($cash * $ratio, 2);
            $fix = round($payable - ($card + $cash), 2);
            if (abs($fix) >= 0.01) {
                $cash = round($cash + $fix, 2);
            }
        }

        return [$card, $cash];
    }

    public static function installmentsCollectedInRange(int $atelierId, string $start, string $end): float
    {
        return (float) Installment::query()
            ->where('is_paid', true)
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$start, $end])
            ->whereHas('purchase', function ($q) use ($atelierId) {
                $q->forAtelier($atelierId);
            })
            ->sum('amount');
    }

    public static function debtsCollectedInRange(int $atelierId, string $start, string $end): float
    {
        if (! Schema::hasColumn('purchases', 'is_debt_settled')) {
            return 0.0;
        }

        return (float) Purchase::query()
            ->forAtelier($atelierId)
            ->where('payment_type', 'debt')
            ->where('is_debt_settled', true)
            ->whereNotNull('debt_settled_at')
            ->whereBetween('debt_settled_at', [$start, $end])
            ->get()
            ->sum(function (Purchase $purchase) {
                return (float) $purchase->debt_settled_card_amount + (float) $purchase->debt_settled_cash_amount;
            });
    }

    public static function totalUncollectedDebts(int $atelierId): float
    {
        return self::openDebtsAsOf($atelierId, Carbon::now('Asia/Tehran'));
    }

    /**
     * مجموع بدهی‌های قرضی باز تا پایان یک روز.
     */
    public static function openDebtsAsOf(int $atelierId, Carbon $asOfDate): float
    {
        if (! Schema::hasColumn('purchases', 'is_debt_settled')) {
            return 0.0;
        }

        $endString = $asOfDate->copy()->setTimezone('Asia/Tehran')->endOfDay()->format('Y-m-d H:i:s');

        return (float) Purchase::query()
            ->forAtelier($atelierId)
            ->where('payment_type', 'debt')
            ->where('total_amount', '>', 0)
            ->where('created_at', '<=', $endString)
            ->where(function ($q) use ($endString) {
                $q->where('is_debt_settled', false)
                    ->orWhere(function ($q2) use ($endString) {
                        $q2->where('is_debt_settled', true)
                            ->whereNotNull('debt_settled_at')
                            ->where('debt_settled_at', '>', $endString);
                    });
            })
            ->with('purchasedProducts')
            ->get()
            ->sum(function (Purchase $purchase) {
                return $purchase->payableAmount();
            });
    }

    public static function totalUncollectedInstallments(int $atelierId): float
    {
        return (float) Installment::query()
            ->where('is_paid', false)
            ->whereHas('purchase', function ($q) use ($atelierId) {
                $q->forAtelier($atelierId)->where('payment_type', 'installment');
            })
            ->sum('amount');
    }

    public static function salesAndProfitForDate(int $atelierId, Carbon $dateTehran): array
    {
        $start = $dateTehran->copy()->setTimezone('Asia/Tehran')->startOfDay();
        $end = $dateTehran->copy()->setTimezone('Asia/Tehran')->endOfDay();

        return self::salesAndProfitForRange($atelierId, $start, $end);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\ReturnedProduct>  $returnedProducts
     * @return array{0: float, 1: float}
     */
    public static function sumReturnedProducts($returnedProducts): array
    {
        $totalReturns = 0.0;
        $totalReturnsPurchase = 0.0;

        foreach ($returnedProducts as $returned) {
            $totalReturns += (float) $returned->sale_price;
            $totalReturnsPurchase += $returned->product
                ? (float) $returned->product->purchase_price
                : 0.0;
        }

        return [$totalReturns, $totalReturnsPurchase];
    }
}
