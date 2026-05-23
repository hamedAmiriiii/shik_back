<?php

namespace App\Services;

use App\Models\Installment;
use App\Models\Purchase;
use App\Models\ReturnedProduct;
use Carbon\Carbon;

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

        foreach ($purchases as $purchase) {
            $lineSales = $purchase->remainingLineSalesTotal();
            if ($lineSales <= 0) {
                continue;
            }

            $lineCost = $purchase->remainingLinePurchaseCost();

            if ($purchase->isInstallment()) {
                $totalSales += (float) $purchase->paid_amount + (float) $purchase->credit_used;
            } else {
                $totalSales += $lineSales;
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

        $installmentsCollected = self::installmentsCollectedInRange($atelierId, $startString, $endString);
        $cashAndCardTotal = round($cardAmount + $cashAmount, 2);
        $settlementTotal = round($cashAndCardTotal + $creditUsedTotal + $installmentsCollected, 2);
        $totalCollected = $cashAndCardTotal + $installmentsCollected;

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
            'total_collected' => (float) round($totalCollected, 2),
            'uncollected_installments' => (float) $uncollectedFromPeriodSales,
            'credit_used_total' => (float) round($creditUsedTotal, 2),
            'settlement_total' => (float) $settlementTotal,
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

        $payable = max(0, round($lineSales - (float) $purchase->credit_used, 2));
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
