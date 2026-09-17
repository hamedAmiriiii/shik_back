<?php

namespace App\Services\SmartCustomer;

use App\Models\ShopCustomerMetric;
use App\Models\UserShiksho;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * کالاهای بد / خوب بر اساس چرخه خرید مشتری.
 *
 * بد: بیش از ۳ خرید، الان بیش از ۲۰٪ دیرتر از میانگین فاصله‌شان نیامده‌اند.
 * خوب: برعکس — هنوز داخل یا جلوتر از چرخه میانگین‌شان هستند.
 */
class ProductCycleInsightService
{
    public const MIN_FREQUENCY = 4; // بیش از ۳ بار

    public const OVERDUE_FACTOR = 1.2; // ۲۰٪ بیشتر از میانگین

    /**
     * @param  'bad'|'good'  $type
     * @return array<string, mixed>
     */
    public static function analyze(int $atelierId, string $type): array
    {
        $type = $type === 'good' ? 'good' : 'bad';

        if ($atelierId <= 0 || ! Schema::hasTable('purchases') || ! Schema::hasTable('purchased_products')) {
            return self::empty($type);
        }

        $metrics = self::eligibleMetrics($atelierId);
        $selected = [];

        foreach ($metrics as $m) {
            $avg = (float) $m->avg_days_between;
            if ($avg <= 0) {
                continue;
            }
            $recency = (int) $m->recency_days;
            $threshold = $avg * self::OVERDUE_FACTOR;
            $isOverdue = $recency > $threshold;

            if ($type === 'bad' && ! $isOverdue) {
                continue;
            }
            if ($type === 'good' && $isOverdue) {
                continue;
            }

            $selected[] = $m;
        }

        if ($selected === []) {
            return self::empty($type, 0);
        }

        $phones = array_map(fn ($m) => (string) $m->phone, $selected);
        $names = UserShiksho::query()
            ->where('atelier_id', $atelierId)
            ->whereIn('phone', $phones)
            ->pluck('name', 'phone');

        $customers = [];
        $productCounts = [];

        foreach ($selected as $m) {
            $last = self::lastProductForPhone($atelierId, (string) $m->phone);
            $productId = $last['product_id'] ?? null;
            $productName = $last['product_name'] ?? null;

            if ($productId) {
                if (! isset($productCounts[$productId])) {
                    $productCounts[$productId] = [
                        'product_id' => $productId,
                        'product_name' => $productName,
                        'customer_count' => 0,
                    ];
                }
                $productCounts[$productId]['customer_count']++;
            }

            $customers[] = [
                'phone' => $m->phone,
                'name' => $names[$m->phone] ?? null,
                'frequency' => (int) $m->frequency,
                'avg_days_between' => round((float) $m->avg_days_between, 1),
                'recency_days' => (int) $m->recency_days,
                'overdue_threshold_days' => round((float) $m->avg_days_between * self::OVERDUE_FACTOR, 1),
                'last_product_id' => $productId,
                'last_product_name' => $productName,
            ];
        }

        usort($productCounts, function ($a, $b) {
            return $b['customer_count'] <=> $a['customer_count'];
        });

        usort($customers, function ($a, $b) {
            return $b['recency_days'] <=> $a['recency_days'];
        });

        return [
            'type' => $type,
            'title' => $type === 'bad' ? 'کالاهای بد' : 'کالاهای خوب',
            'description' => $type === 'bad'
                ? 'مشتریانی با بیش از ۳ خرید که الان بیش از ۲۰٪ از میانگین فاصله خریدشان دیر کرده‌اند — آخرین کالا و کالاهای مشترک.'
                : 'همان معیار، برعکس: هنوز داخل چرخه میانگین‌شان هستند — آخرین کالا و کالاهای مشترک.',
            'customer_count' => count($customers),
            'common_products' => array_values($productCounts),
            'customers' => array_slice($customers, 0, 100),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, ShopCustomerMetric|\stdClass>
     */
    protected static function eligibleMetrics(int $atelierId)
    {
        if (Schema::hasTable('shop_customer_metrics')) {
            return ShopCustomerMetric::query()
                ->where('atelier_id', $atelierId)
                ->where('frequency', '>', 3)
                ->whereNotNull('avg_days_between')
                ->where('avg_days_between', '>', 0)
                ->get();
        }

        // fallback بدون جدول metrics
        $now = now('Asia/Tehran');
        $rows = DB::table('purchases')
            ->where('atelier_id', $atelierId)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->where('total_amount', '>', 0)
            ->select([
                'phone',
                DB::raw('COUNT(*) as frequency'),
                DB::raw('MIN(created_at) as first_purchase_at'),
                DB::raw('MAX(created_at) as last_purchase_at'),
            ])
            ->groupBy('phone')
            ->having('frequency', '>', 3)
            ->get();

        return $rows->map(function ($row) use ($now) {
            $first = $row->first_purchase_at ? \Carbon\Carbon::parse($row->first_purchase_at) : null;
            $last = $row->last_purchase_at ? \Carbon\Carbon::parse($row->last_purchase_at) : null;
            $frequency = (int) $row->frequency;
            $avg = null;
            if ($first && $last && $frequency > 1) {
                $span = max(1, $first->diffInDays($last));
                $avg = $span / ($frequency - 1);
            }
            $m = new ShopCustomerMetric([
                'phone' => $row->phone,
                'frequency' => $frequency,
                'avg_days_between' => $avg,
                'recency_days' => $last ? (int) $last->diffInDays($now) : 9999,
            ]);

            return $m;
        })->filter(fn ($m) => $m->avg_days_between && (float) $m->avg_days_between > 0)->values();
    }

    /**
     * @return array{product_id:?int,product_name:?string}
     */
    protected static function lastProductForPhone(int $atelierId, string $phone): array
    {
        $purchaseId = DB::table('purchases')
            ->where('atelier_id', $atelierId)
            ->where('phone', $phone)
            ->where('total_amount', '>', 0)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('id');

        if (! $purchaseId) {
            return ['product_id' => null, 'product_name' => null];
        }

        $line = DB::table('purchased_products as pp')
            ->leftJoin('products as p', 'p.id', '=', 'pp.product_id')
            ->where('pp.purchase_id', $purchaseId)
            ->orderByDesc('pp.quantity')
            ->orderByDesc('pp.id')
            ->select([
                'pp.product_id',
                'pp.item_name',
                'p.name as product_name',
            ])
            ->first();

        if (! $line) {
            return ['product_id' => null, 'product_name' => null];
        }

        $name = trim((string) ($line->product_name ?: $line->item_name ?: ''));

        return [
            'product_id' => $line->product_id ? (int) $line->product_id : null,
            'product_name' => $name !== '' ? $name : ('کالا #'.($line->product_id ?: '?')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function empty(string $type, int $customerCount = 0): array
    {
        return [
            'type' => $type,
            'title' => $type === 'bad' ? 'کالاهای بد' : 'کالاهای خوب',
            'description' => $type === 'bad'
                ? 'مشتریانی با بیش از ۳ خرید که بیش از ۲۰٪ از میانگین فاصله‌شان دیر کرده‌اند.'
                : 'مشتریانی با بیش از ۳ خرید که هنوز داخل چرخه میانگین‌شان هستند.',
            'customer_count' => $customerCount,
            'common_products' => [],
            'customers' => [],
        ];
    }
}
