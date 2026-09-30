<?php

namespace App\Services\SmartCustomer;

use App\Models\ShopCustomerSegment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * کمپین کالایی: انتخاب کالا (تخفیف‌دار / کم‌فروش)، پیدا کردن مشتری‌هایی که خریده‌اند / نخریده‌اند /
 * وقت خرید دوباره‌شان رسیده، و سنجش خرید همان کالاها بعد از ارسال.
 */
class ProductCampaignService
{
    public const SALES_WINDOW_DAYS = 60;

    public const MAX_AUDIENCE = 10000;

    public static function ready(): bool
    {
        return Schema::hasTable('products')
            && Schema::hasTable('purchases')
            && Schema::hasTable('purchased_products')
            && Schema::hasTable('shop_customer_metrics');
    }

    /**
     * @param  'discounted'|'slow'|'all'  $filter
     * @return array<int, array<string, mixed>>
     */
    public static function products(int $atelierId, string $filter, string $search = ''): array
    {
        if (! self::ready()) {
            return [];
        }

        $since = Carbon::now('Asia/Tehran')->subDays(self::SALES_WINDOW_DAYS);
        $sales = DB::table('purchased_products as pp')
            ->join('purchases as p', 'p.id', '=', 'pp.purchase_id')
            ->where('p.atelier_id', $atelierId)
            ->where('p.created_at', '>=', $since)
            ->whereNotNull('pp.product_id')
            ->groupBy('pp.product_id')
            ->select([
                'pp.product_id',
                DB::raw('COALESCE(SUM(pp.quantity), 0) as sold_qty'),
                DB::raw("COUNT(DISTINCT NULLIF(p.phone, '')) as buyers"),
            ]);

        $hasOriginal = Schema::hasColumn('products', 'original_sale_price');
        $hasUnit = Schema::hasColumn('products', 'unit_type');

        $q = DB::table('products as pr')
            ->leftJoinSub($sales, 's', 's.product_id', '=', 'pr.id')
            ->where('pr.atelier_id', $atelierId)
            ->whereNull('pr.deleted_at')
            ->select(array_filter([
                'pr.id',
                'pr.name',
                'pr.sale_price',
                $hasOriginal ? 'pr.original_sale_price' : null,
                'pr.quantity',
                $hasUnit ? 'pr.unit_type' : null,
                DB::raw('COALESCE(s.sold_qty, 0) as sold_qty'),
                DB::raw('COALESCE(s.buyers, 0) as buyers'),
            ]));

        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('pr.name', 'like', "%{$search}%")
                    ->orWhere('pr.barcode', 'like', "%{$search}%");
            });
        }

        if ($filter === 'discounted') {
            if (! $hasOriginal) {
                return [];
            }
            $q->whereNotNull('pr.original_sale_price')
                ->whereColumn('pr.original_sale_price', '>', 'pr.sale_price')
                ->orderByRaw('(pr.original_sale_price - pr.sale_price) / pr.original_sale_price DESC');
        } elseif ($filter === 'slow') {
            $q->where('pr.quantity', '>', 0)
                ->orderBy('sold_qty')
                ->orderByDesc('pr.quantity');
        } else {
            $q->orderByDesc('sold_qty')->orderBy('pr.name');
        }

        return $q->limit(80)->get()->map(function ($r) use ($hasOriginal) {
            $sale = (float) $r->sale_price;
            $original = $hasOriginal && $r->original_sale_price !== null ? (float) $r->original_sale_price : null;
            $discountPct = $original && $original > $sale && $original > 0
                ? (int) round(($original - $sale) * 100 / $original)
                : 0;

            return [
                'id' => (int) $r->id,
                'name' => (string) $r->name,
                'sale_price' => round($sale, 0),
                'original_sale_price' => $original !== null ? round($original, 0) : null,
                'discount_pct' => $discountPct,
                'quantity' => (float) $r->quantity,
                'unit_type' => $r->unit_type ?? 'piece',
                'sold_qty' => (float) $r->sold_qty,
                'buyers' => (int) $r->buyers,
            ];
        })->values()->all();
    }

    /**
     * @param  array{product_ids:array<int,int>,mode:string,days?:?int,exclude_product_ids?:array<int,int>,segment?:?string}  $params
     * @return array{total:int,customers:array<int,array<string,mixed>>}
     */
    public static function audience(int $atelierId, array $params): array
    {
        if (! self::ready()) {
            return ['total' => 0, 'customers' => []];
        }

        $productIds = array_values(array_unique(array_map('intval', $params['product_ids'] ?? [])));
        $excludeIds = array_values(array_diff(
            array_unique(array_map('intval', $params['exclude_product_ids'] ?? [])),
            $productIds
        ));
        $mode = (string) ($params['mode'] ?? 'bought');
        $days = isset($params['days']) && (int) $params['days'] > 0 ? (int) $params['days'] : null;
        $segment = trim((string) ($params['segment'] ?? ''));

        if ($productIds === []) {
            return ['total' => 0, 'customers' => []];
        }

        $now = Carbon::now('Asia/Tehran');
        $since = $days ? $now->copy()->subDays($days) : null;

        $bought = DB::table('purchased_products as pp')
            ->join('purchases as p', 'p.id', '=', 'pp.purchase_id')
            ->where('p.atelier_id', $atelierId)
            ->whereIn('pp.product_id', $productIds)
            ->whereNotNull('p.phone')
            ->where('p.phone', '!=', '')
            ->when($since, fn ($q) => $q->where('p.created_at', '>=', $since))
            ->groupBy('p.phone')
            ->select([
                'p.phone',
                DB::raw('COUNT(DISTINCT p.id) as orders'),
                DB::raw('COALESCE(SUM(pp.quantity), 0) as qty'),
                DB::raw('MAX(p.created_at) as last_bought_at'),
            ]);

        $q = DB::table('shop_customer_metrics as m')
            ->leftJoin('shop_customer_segments as s', function ($join) {
                $join->on('s.atelier_id', '=', 'm.atelier_id')->on('s.phone', '=', 'm.phone');
            })
            ->leftJoin('user_shiksho as u', function ($join) {
                $join->on('u.atelier_id', '=', 'm.atelier_id')->on('u.phone', '=', 'm.phone');
            })
            ->where('m.atelier_id', $atelierId);

        $due = [];
        if ($mode === 'not_bought') {
            $q->leftJoinSub($bought, 'b', 'b.phone', '=', 'm.phone')->whereNull('b.phone');
        } elseif ($mode === 'repurchase_due') {
            $due = self::repurchaseDue($atelierId, $productIds, $now);
            if ($due === []) {
                return ['total' => 0, 'customers' => []];
            }
            $q->joinSub($bought, 'b', 'b.phone', '=', 'm.phone')->whereIn('m.phone', array_keys($due));
        } else {
            $q->joinSub($bought, 'b', 'b.phone', '=', 'm.phone');
        }

        if ($excludeIds !== []) {
            $q->whereNotExists(function ($sub) use ($atelierId, $excludeIds) {
                $sub->select(DB::raw(1))
                    ->from('purchased_products as xpp')
                    ->join('purchases as xp', 'xp.id', '=', 'xpp.purchase_id')
                    ->where('xp.atelier_id', $atelierId)
                    ->whereColumn('xp.phone', 'm.phone')
                    ->whereIn('xpp.product_id', $excludeIds);
            });
        }

        if ($segment !== '') {
            $q->where('s.primary_segment', $segment);
        }

        $isNotBought = $mode === 'not_bought';
        $q->select(array_merge([
            'm.phone',
            'm.recency_days',
            'm.frequency',
            'm.monetary',
            's.primary_segment',
            'u.name',
        ], $isNotBought ? [] : ['b.orders', 'b.qty', 'b.last_bought_at']));

        if ($isNotBought) {
            $q->orderByDesc('m.monetary');
        } elseif ($mode !== 'repurchase_due') {
            $q->orderByDesc('b.last_bought_at');
        }

        $rows = $q->limit(self::MAX_AUDIENCE)->get();
        $labels = ShopCustomerSegment::labels();

        $customers = $rows->map(function ($r) use ($labels, $isNotBought, $due) {
            $phone = (string) $r->phone;

            return [
                'phone' => $phone,
                'name' => $r->name,
                'primary_segment' => $r->primary_segment,
                'segment_label' => $labels[$r->primary_segment] ?? $r->primary_segment,
                'recency_days' => (int) $r->recency_days,
                'frequency' => (int) $r->frequency,
                'monetary' => round((float) $r->monetary, 0),
                'orders' => $isNotBought ? 0 : (int) $r->orders,
                'qty' => $isNotBought ? 0 : (float) $r->qty,
                'last_bought_at' => $isNotBought ? null : $r->last_bought_at,
                'due_product' => $due[$phone]['product'] ?? null,
                'overdue_days' => isset($due[$phone]) ? $due[$phone]['overdue_days'] : null,
            ];
        });

        if ($mode === 'repurchase_due') {
            $customers = $customers->sortBy('overdue_days');
        }

        return [
            'total' => $customers->count(),
            'customers' => $customers->values()->all(),
        ];
    }

    /**
     * مشتری‌هایی که فاصله‌ی معمول خرید یکی از این کالاها برایشان گذشته است.
     * فاصله‌ی شخصی (با حداقل ۲ خرید) و در غیر این صورت میانه‌ی فاصله‌ی همه‌ی خریداران آن کالا.
     *
     * @param  array<int,int>  $productIds
     * @return array<string, array{product:string,overdue_days:int}>
     */
    protected static function repurchaseDue(int $atelierId, array $productIds, Carbon $now): array
    {
        $rows = DB::table('purchased_products as pp')
            ->join('purchases as p', 'p.id', '=', 'pp.purchase_id')
            ->leftJoin('products as pr', 'pr.id', '=', 'pp.product_id')
            ->where('p.atelier_id', $atelierId)
            ->whereIn('pp.product_id', $productIds)
            ->whereNotNull('p.phone')
            ->where('p.phone', '!=', '')
            ->select([
                'p.phone',
                'pp.product_id',
                'pr.name as product_name',
                DB::raw('DATE(p.created_at) as d'),
            ])
            ->distinct()
            ->orderBy('d')
            ->get();

        /** @var array<int, array<string, array<int,string>>> $dates */
        $dates = [];
        $names = [];
        foreach ($rows as $r) {
            $pid = (int) $r->product_id;
            $dates[$pid][(string) $r->phone][] = (string) $r->d;
            $names[$pid] = (string) ($r->product_name ?: ('کالا #'.$pid));
        }

        $productInterval = [];
        $personal = [];
        foreach ($dates as $pid => $byPhone) {
            $intervals = [];
            foreach ($byPhone as $phone => $list) {
                $n = count($list);
                if ($n < 2) {
                    continue;
                }
                $span = Carbon::parse($list[0])->diffInDays(Carbon::parse($list[$n - 1]));
                $avg = $span / ($n - 1);
                if ($avg > 0) {
                    $personal[$pid][$phone] = $avg;
                    $intervals[] = $avg;
                }
            }
            if ($intervals !== []) {
                sort($intervals);
                $productInterval[$pid] = $intervals[intdiv(count($intervals), 2)];
            }
        }

        $due = [];
        foreach ($dates as $pid => $byPhone) {
            foreach ($byPhone as $phone => $list) {
                $interval = $personal[$pid][$phone] ?? ($productInterval[$pid] ?? null);
                if (! $interval) {
                    continue;
                }
                $sinceLast = Carbon::parse(end($list), 'Asia/Tehran')->diffInDays($now);
                $overdue = (int) round($sinceLast - $interval);
                // خیلی دیر شده = احتمالاً دیگر این کالا را نمی‌خواهد
                if ($overdue < 0 || $sinceLast > $interval * 4) {
                    continue;
                }
                if (! isset($due[$phone]) || $overdue < $due[$phone]['overdue_days']) {
                    $due[$phone] = ['product' => $names[$pid], 'overdue_days' => $overdue];
                }
            }
        }

        return $due;
    }

    /**
     * از گیرندگان کمپین، چند نفر بعد از ارسال همین کالاها را خریده‌اند.
     *
     * @param  array<int,int>  $productIds
     * @return array<string, mixed>
     */
    public static function conversion(int $atelierId, int $campaignId, array $productIds): array
    {
        $sentPerPhone = DB::table('shop_campaign_logs')
            ->where('campaign_id', $campaignId)
            ->where('atelier_id', $atelierId)
            ->where('status', 'sent')
            ->groupBy('phone')
            ->select(['phone', DB::raw('MIN(created_at) as first_sent_at')]);

        $base = DB::query()
            ->fromSub($sentPerPhone, 'l')
            ->join('purchases as p', function ($join) use ($atelierId) {
                $join->on('p.phone', '=', 'l.phone')
                    ->where('p.atelier_id', '=', $atelierId)
                    ->on('p.created_at', '>=', 'l.first_sent_at');
            })
            ->join('purchased_products as pp', 'pp.purchase_id', '=', 'p.id')
            ->whereIn('pp.product_id', $productIds);

        $totals = (clone $base)
            ->selectRaw('COUNT(DISTINCT l.phone) as buyers, COALESCE(SUM(pp.quantity), 0) as qty, COALESCE(SUM(pp.quantity * pp.sale_price), 0) as revenue')
            ->first();

        $perProduct = (clone $base)
            ->leftJoin('products as pr', 'pr.id', '=', 'pp.product_id')
            ->groupBy('pp.product_id', 'pr.name')
            ->select([
                'pp.product_id',
                'pr.name',
                DB::raw('COUNT(DISTINCT l.phone) as buyers'),
                DB::raw('COALESCE(SUM(pp.quantity), 0) as qty'),
                DB::raw('COALESCE(SUM(pp.quantity * pp.sale_price), 0) as revenue'),
            ])
            ->orderByDesc('qty')
            ->get()
            ->map(fn ($r) => [
                'product_id' => (int) $r->product_id,
                'name' => (string) ($r->name ?: ('کالا #'.$r->product_id)),
                'buyers' => (int) $r->buyers,
                'qty' => (float) $r->qty,
                'revenue' => round((float) $r->revenue, 0),
            ])
            ->values()
            ->all();

        return [
            'buyers' => (int) ($totals->buyers ?? 0),
            'qty' => (float) ($totals->qty ?? 0),
            'revenue' => round((float) ($totals->revenue ?? 0), 0),
            'products' => $perProduct,
        ];
    }

    /**
     * @param  iterable<\App\Models\ShopCampaignAction>  $actions
     * @return array<int,int>
     */
    public static function productIdsFromActions(iterable $actions): array
    {
        $ids = [];
        foreach ($actions as $action) {
            foreach ((array) (($action->config ?? [])['product_ids'] ?? []) as $id) {
                if ((int) $id > 0) {
                    $ids[] = (int) $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
