<?php

namespace App\Services;

use App\Models\Installment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\ReturnedProduct;
use App\Services\ShopSalesReportService;
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
        $days = max(1, min($days, 62));
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
            ->with(['installments', 'purchasedProducts'])
            ->get();

        foreach ($purchases as $purchase) {
            $key = self::tehranDateKeyFromModel($purchase);
            if (! isset($buckets[$key])) {
                continue;
            }

            $lineSales = $purchase->remainingLineSalesTotal();
            if ($lineSales <= 0) {
                continue;
            }

            if ($purchase->isInstallment()) {
                $buckets[$key]['gross_sales'] += (float) $purchase->paid_amount + (float) $purchase->credit_used;
            } else {
                $buckets[$key]['gross_sales'] += $lineSales;
            }

            [$card, $cash] = ShopSalesReportService::settlementForPurchase($purchase, $lineSales);
            $buckets[$key]['card_amount'] += $card;
            $buckets[$key]['cash_amount'] += $cash;

            if ($purchase->isInstallment()) {
                $buckets[$key]['uncollected_installments'] += (float) $purchase->installments
                    ->where('is_paid', false)
                    ->sum('amount');
            }
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

        $paidInstallments = Installment::query()
            ->where('is_paid', true)
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$rangeStart, $rangeEnd])
            ->whereHas('purchase', fn ($q) => $q->forAtelier($atelierId))
            ->get(['amount', 'paid_at']);

        foreach ($paidInstallments as $installment) {
            $key = Carbon::parse($installment->getRawOriginal('paid_at'))
                ->setTimezone('Asia/Tehran')
                ->format('Y-m-d');
            if (! isset($buckets[$key])) {
                continue;
            }
            $buckets[$key]['installments_collected'] += (float) $installment->amount;
        }

        $daily = [];
        $periodTotalSales = 0.0;
        foreach ($buckets as $row) {
            $row['total_sales'] = (float) ($row['gross_sales'] - $row['total_returns']);
            $row['cash_and_card_total'] = (float) ($row['card_amount'] + $row['cash_amount']);
            $row['total_collected'] = (float) ($row['cash_and_card_total'] + $row['installments_collected']);
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
            'total_uncollected_installments' => ShopSalesReportService::totalUncollectedInstallments($atelierId),
            'daily' => $daily,
        ];
    }

    protected static function metricsForSingleDay(int $atelierId, Carbon $dateTehran): array
    {
        $report = ShopSalesReportService::salesAndProfitForDate($atelierId, $dateTehran);

        $start = $dateTehran->copy()->startOfDay()->format('Y-m-d H:i:s');
        $end = $dateTehran->copy()->endOfDay()->format('Y-m-d H:i:s');
        $purchasesCount = Purchase::query()
            ->forAtelier($atelierId)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        return [
            'total_sales' => $report['sales'],
            'gross_sales' => $report['gross_sales'],
            'total_returns' => $report['returns'],
            'total_profit' => $report['profit'],
            'credit_earned_from_purchases' => $report['credit_earned_from_purchases'],
            'manual_credit_granted' => $report['manual_credit_granted'],
            'total_credit_granted' => $report['total_credit_granted'],
            'card_amount' => $report['card_amount'],
            'cash_amount' => $report['cash_amount'],
            'cash_and_card_total' => $report['cash_and_card_total'],
            'installments_collected' => $report['installments_collected'],
            'total_collected' => $report['total_collected'],
            'uncollected_installments' => $report['uncollected_installments'],
            'credit_used_total' => $report['credit_used_total'],
            'settlement_total' => $report['settlement_total'],
            'purchases_count' => $purchasesCount,
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
            'cash_and_card_total' => 0.0,
            'installments_collected' => 0.0,
            'total_collected' => 0.0,
            'uncollected_installments' => 0.0,
            'purchases_count' => 0,
        ];
    }

    protected static function tehranDateKeyFromModel(object $model): string
    {
        $raw = $model->getRawOriginal('created_at');

        return Carbon::parse($raw)->setTimezone('Asia/Tehran')->format('Y-m-d');
    }
}
