<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\ReturnedProduct;
use Carbon\Carbon;
use Morilog\Jalali\Jalalian;

class ShopDashboardService
{
    /**
     * خلاصهٔ صفحهٔ اصلی فروشگاه برای یک روز (پیش‌فرض: امروز تهران).
     */
    public static function summary(int $atelierId, ?Carbon $dateTehran = null): array
    {
        $dateTehran = ($dateTehran ?? Carbon::now())->copy()->setTimezone('Asia/Tehran');
        $metrics = self::metricsForSingleDay($atelierId, $dateTehran);
        $productsCount = Product::where('atelier_id', $atelierId)->count();

        return [
            'date' => $dateTehran->format('Y-m-d'),
            'date_jalali' => Jalalian::fromCarbon($dateTehran)->format('Y-m-d'),
            'products_count' => $productsCount,
            'today' => $metrics,
        ];
    }

    /**
     * فروش خالص به تفکیک روز — پیش‌فرض ۱۰ روز اخیر (شامل امروز).
     */
    public static function salesByDay(int $atelierId, int $days = 10): array
    {
        $days = max(1, min($days, 31));
        $end = Carbon::now('Asia/Tehran')->endOfDay();
        $start = Carbon::now('Asia/Tehran')->startOfDay()->subDays($days - 1);

        $buckets = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i);
            $key = $day->format('Y-m-d');
            $buckets[$key] = self::emptyDayBucket($day);
        }

        $rangeStart = $start->format('Y-m-d H:i:s');
        $rangeEnd = $end->format('Y-m-d H:i:s');

        $purchases = Purchase::query()
            ->forAtelier($atelierId)
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->with(['installments'])
            ->get(['id', 'total_amount', 'credit_used', 'payment_type', 'card_amount', 'cash_amount', 'created_at']);

        foreach ($purchases as $purchase) {
            $key = self::tehranDateKeyFromModel($purchase);
            if (! isset($buckets[$key])) {
                continue;
            }

            $actualPaid = $purchase->isInstallment()
                ? (float) $purchase->paid_amount
                : (float) $purchase->total_amount;

            $buckets[$key]['gross_sales'] += $actualPaid + (float) $purchase->credit_used;
            $buckets[$key]['card_amount'] += (float) $purchase->card_amount;
            $buckets[$key]['cash_amount'] += (float) $purchase->cash_amount;
            $buckets[$key]['purchases_count']++;
        }

        $returns = ReturnedProduct::query()
            ->forAtelier($atelierId)
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->get(['sale_price', 'created_at']);

        foreach ($returns as $returned) {
            $key = self::tehranDateKeyFromModel($returned);
            if (! isset($buckets[$key])) {
                continue;
            }
            $buckets[$key]['total_returns'] += (float) $returned->sale_price;
        }

        $daily = [];
        $periodTotalSales = 0.0;
        foreach ($buckets as $row) {
            $row['total_sales'] = (float) ($row['gross_sales'] - $row['total_returns']);
            $periodTotalSales += $row['total_sales'];
            $daily[] = $row;
        }

        return [
            'days' => $days,
            'from_date' => $start->format('Y-m-d'),
            'to_date' => $end->format('Y-m-d'),
            'from_date_jalali' => Jalalian::fromCarbon($start)->format('Y-m-d'),
            'to_date_jalali' => Jalalian::fromCarbon($end)->format('Y-m-d'),
            'period_total_sales' => (float) $periodTotalSales,
            'daily' => $daily,
        ];
    }

    protected static function metricsForSingleDay(int $atelierId, Carbon $dateTehran): array
    {
        $start = $dateTehran->copy()->startOfDay()->format('Y-m-d H:i:s');
        $end = $dateTehran->copy()->endOfDay()->format('Y-m-d H:i:s');

        $purchases = Purchase::query()
            ->forAtelier($atelierId)
            ->whereBetween('created_at', [$start, $end])
            ->with(['purchasedProducts', 'installments'])
            ->get();

        $totalSales = 0.0;
        $cardAmount = 0.0;
        $cashAmount = 0.0;
        $totalPurchaseCost = 0.0;
        $totalCreditEarned = 0.0;

        foreach ($purchases as $purchase) {
            $actualPaid = $purchase->isInstallment()
                ? (float) $purchase->paid_amount
                : (float) $purchase->total_amount;

            $totalSales += $actualPaid + (float) $purchase->credit_used;
            $cardAmount += (float) $purchase->card_amount;
            $cashAmount += (float) $purchase->cash_amount;
            $totalCreditEarned += (float) $purchase->credit_earned;

            foreach ($purchase->purchasedProducts as $item) {
                $totalPurchaseCost += $item->quantity * (float) $item->purchase_price;
            }
        }

        $returns = ReturnedProduct::query()
            ->forAtelier($atelierId)
            ->whereBetween('created_at', [$start, $end])
            ->with('product')
            ->get();

        $totalReturns = 0.0;
        $returnsPurchaseCost = 0.0;
        foreach ($returns as $returned) {
            $totalReturns += (float) $returned->sale_price;
            $returnsPurchaseCost += $returned->product
                ? (float) $returned->product->purchase_price
                : 0.0;
        }

        $netSales = $totalSales - $totalReturns;
        $netProfit = $netSales - ($totalPurchaseCost - $returnsPurchaseCost) - $totalCreditEarned;

        return [
            'total_sales' => (float) $netSales,
            'gross_sales' => (float) $totalSales,
            'total_returns' => (float) $totalReturns,
            'total_profit' => (float) $netProfit,
            'card_amount' => (float) $cardAmount,
            'cash_amount' => (float) $cashAmount,
            'purchases_count' => $purchases->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function emptyDayBucket(Carbon $day): array
    {
        return [
            'date' => $day->format('Y-m-d'),
            'date_jalali' => Jalalian::fromCarbon($day)->format('Y-m-d'),
            'total_sales' => 0.0,
            'gross_sales' => 0.0,
            'total_returns' => 0.0,
            'card_amount' => 0.0,
            'cash_amount' => 0.0,
            'purchases_count' => 0,
        ];
    }

    protected static function tehranDateKeyFromModel(object $model): string
    {
        $raw = $model->getRawOriginal('created_at');

        return Carbon::parse($raw)->setTimezone('Asia/Tehran')->format('Y-m-d');
    }
}
