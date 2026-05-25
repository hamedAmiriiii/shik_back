<?php

namespace App\Services;

use App\Models\PurchaseItemReturn;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Morilog\Jalali\Jalalian;

class PurchaseItemReturnGridService
{
    /**
     * @return array<string, mixed>
     */
    public static function gridForMonth(int $atelierId, ?int $jalaliYear = null, ?int $jalaliMonth = null): array
    {
        $now = Jalalian::fromCarbon(Carbon::now('Asia/Tehran'));
        $jalaliYear = $jalaliYear ?? (int) $now->getYear();
        $jalaliMonth = $jalaliMonth ?? (int) $now->getMonth();

        $monthStartJalali = new Jalalian($jalaliYear, $jalaliMonth, 1, 0, 0, 0);
        $daysInMonth = $monthStartJalali->getMonthDays();
        $monthEndJalali = new Jalalian($jalaliYear, $jalaliMonth, $daysInMonth, 23, 59, 59);

        $start = $monthStartJalali->toCarbon()->setTimezone('Asia/Tehran')->startOfDay();
        $end = $monthEndJalali->toCarbon()->setTimezone('Asia/Tehran')->endOfDay();

        if (! Schema::hasTable('purchase_item_returns')) {
            return self::emptyGridResponse($jalaliYear, $jalaliMonth, $monthStartJalali, $monthEndJalali, $start, $end, $now);
        }

        $rangeStart = $start->format('Y-m-d H:i:s');
        $rangeEnd = $end->format('Y-m-d H:i:s');

        $items = PurchaseItemReturn::query()
            ->forAtelier($atelierId)
            ->with(['product:id,name,barcode'])
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $rows = [];
        $dailyBuckets = [];
        $monthTotalSale = 0.0;
        $monthTotalPurchase = 0.0;
        $monthTotalQty = 0;

        foreach ($items as $item) {
            $row = self::formatTransactionRow($item);
            $rows[] = $row;

            $monthTotalSale += (float) $row['return_sale_total'];
            $monthTotalPurchase += (float) $row['return_purchase_total'];
            $monthTotalQty += (int) $row['quantity'];

            $dateKey = $row['date'];
            if (! isset($dailyBuckets[$dateKey])) {
                $dailyBuckets[$dateKey] = [
                    'date' => $dateKey,
                    'date_jalali' => $row['date_jalali'],
                    'transactions_count' => 0,
                    'quantity' => 0,
                    'total_sale_price' => 0.0,
                    'total_purchase_price' => 0.0,
                ];
            }
            $dailyBuckets[$dateKey]['transactions_count']++;
            $dailyBuckets[$dateKey]['quantity'] += (int) $row['quantity'];
            $dailyBuckets[$dateKey]['total_sale_price'] += (float) $row['return_sale_total'];
            $dailyBuckets[$dateKey]['total_purchase_price'] += (float) $row['return_purchase_total'];
        }

        $daily = array_values($dailyBuckets);
        usort($daily, fn ($a, $b) => strcmp($a['date'], $b['date']));
        foreach ($daily as &$day) {
            $day['total_sale_price'] = round($day['total_sale_price'], 2);
            $day['total_purchase_price'] = round($day['total_purchase_price'], 2);
        }
        unset($day);

        $isCurrentMonth = $jalaliYear === (int) $now->getYear()
            && $jalaliMonth === (int) $now->getMonth();

        return [
            'filter' => [
                'year' => $jalaliYear,
                'month' => $jalaliMonth,
                'month_name' => self::jalaliMonthName($jalaliMonth),
                'is_current_month' => $isCurrentMonth,
            ],
            'from_date' => $start->format('Y-m-d'),
            'to_date' => $end->format('Y-m-d'),
            'from_date_jalali' => $monthStartJalali->format('Y-m-d'),
            'to_date_jalali' => $monthEndJalali->format('Y-m-d'),
            'month_total_sale_price' => round($monthTotalSale, 2),
            'month_total_purchase_price' => round($monthTotalPurchase, 2),
            'month_total_quantity' => $monthTotalQty,
            'transactions_count' => count($rows),
            'daily' => $daily,
            'rows' => $rows,
            'transactions' => $rows,
            'table_ready' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function formatTransactionRow(PurchaseItemReturn $item): array
    {
        $createdRaw = $item->getRawOriginal('created_at');
        $createdCarbon = Carbon::parse($createdRaw)->setTimezone('Asia/Tehran');
        $product = $item->product;

        return [
            'id' => (int) $item->id,
            'purchase_id' => (int) $item->purchase_id,
            'purchased_product_id' => $item->purchased_product_id ? (int) $item->purchased_product_id : null,
            'product_id' => (int) $item->product_id,
            'product_name' => $product?->name,
            'barcode' => $product?->barcode,
            'quantity' => (int) $item->quantity,
            'sale_price' => (float) $item->sale_price,
            'purchase_price' => (float) $item->purchase_price,
            'return_sale_total' => (float) $item->return_sale_total,
            'return_purchase_total' => (float) $item->return_purchase_total,
            'phone' => $item->phone,
            'payment_type' => $item->payment_type,
            'credit_used_refund' => (float) $item->credit_used_refund,
            'credit_earned_reversed' => (float) $item->credit_earned_reversed,
            'size' => $item->size,
            'color' => $item->color,
            'date' => $createdCarbon->format('Y-m-d'),
            'date_jalali' => Jalalian::fromCarbon($createdCarbon)->format('Y-m-d'),
            'created_at' => Jalalian::fromCarbon($createdCarbon)->format('Y-m-d H:i:s'),
            'user_name' => $item->user_name,
            'notes' => $item->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function emptyGridResponse(
        int $jalaliYear,
        int $jalaliMonth,
        Jalalian $monthStartJalali,
        Jalalian $monthEndJalali,
        Carbon $start,
        Carbon $end,
        Jalalian $now
    ): array {
        $isCurrentMonth = $jalaliYear === (int) $now->getYear()
            && $jalaliMonth === (int) $now->getMonth();

        return [
            'filter' => [
                'year' => $jalaliYear,
                'month' => $jalaliMonth,
                'month_name' => self::jalaliMonthName($jalaliMonth),
                'is_current_month' => $isCurrentMonth,
            ],
            'from_date' => $start->format('Y-m-d'),
            'to_date' => $end->format('Y-m-d'),
            'from_date_jalali' => $monthStartJalali->format('Y-m-d'),
            'to_date_jalali' => $monthEndJalali->format('Y-m-d'),
            'month_total_sale_price' => 0.0,
            'month_total_purchase_price' => 0.0,
            'month_total_quantity' => 0,
            'transactions_count' => 0,
            'daily' => [],
            'rows' => [],
            'transactions' => [],
            'table_ready' => false,
        ];
    }

    protected static function jalaliMonthName(int $month): string
    {
        $months = [
            1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر',
            5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان',
            9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
        ];

        return $months[$month] ?? '';
    }
}
