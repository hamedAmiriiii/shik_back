<?php

namespace App\Services\SmartCustomer;

use App\Models\ShopCustomerMetric;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomerMetricsComputer
{
    public static function tableReady(): bool
    {
        return Schema::hasTable('shop_customer_metrics')
            && Schema::hasTable('purchases');
    }

    /**
     * @return array{processed:int}
     */
    public static function computeForAtelier(int $atelierId): array
    {
        if (! self::tableReady() || $atelierId <= 0) {
            return ['processed' => 0];
        }

        $thresholds = ShopSegmentThresholdService::forAtelier($atelierId);
        $window = (string) $thresholds->metrics_window;
        $now = Carbon::now('Asia/Tehran');
        $since30 = $now->copy()->subDays(30);
        $since90 = $now->copy()->subDays(90);

        $base = DB::table('purchases')
            ->where('atelier_id', $atelierId)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->where('total_amount', '>', 0);

        if ($window !== 'all' && is_numeric($window)) {
            $base->where('created_at', '>=', $now->copy()->subDays((int) $window));
        }

        $aggregates = (clone $base)
            ->select([
                'phone',
                DB::raw('COUNT(*) as frequency'),
                DB::raw('SUM(total_amount) as monetary'),
                DB::raw('MIN(created_at) as first_purchase_at'),
                DB::raw('MAX(created_at) as last_purchase_at'),
            ])
            ->groupBy('phone')
            ->get();

        $window30 = DB::table('purchases')
            ->where('atelier_id', $atelierId)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->where('total_amount', '>', 0)
            ->where('created_at', '>=', $since30)
            ->select([
                'phone',
                DB::raw('COUNT(*) as c30'),
                DB::raw('SUM(total_amount) as m30'),
            ])
            ->groupBy('phone')
            ->get()
            ->keyBy('phone');

        $window90 = DB::table('purchases')
            ->where('atelier_id', $atelierId)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->where('total_amount', '>', 0)
            ->where('created_at', '>=', $since90)
            ->select([
                'phone',
                DB::raw('COUNT(*) as c90'),
                DB::raw('SUM(total_amount) as m90'),
            ])
            ->groupBy('phone')
            ->get()
            ->keyBy('phone');

        $processed = 0;
        $seenPhones = [];

        foreach ($aggregates as $row) {
            $phone = (string) $row->phone;
            if ($phone === '') {
                continue;
            }
            $seenPhones[$phone] = true;

            $first = $row->first_purchase_at ? Carbon::parse($row->first_purchase_at) : null;
            $last = $row->last_purchase_at ? Carbon::parse($row->last_purchase_at) : null;
            $frequency = (int) $row->frequency;
            $monetary = round((float) $row->monetary, 2);
            $recency = $last ? (int) $last->diffInDays($now) : 9999;
            $avgDays = null;
            if ($first && $last && $frequency > 1) {
                $span = max(1, $first->diffInDays($last));
                $avgDays = round($span / ($frequency - 1), 2);
            }
            $w30 = $window30->get($phone);
            $w90 = $window90->get($phone);

            ShopCustomerMetric::updateOrCreate(
                ['atelier_id' => $atelierId, 'phone' => $phone],
                [
                    'recency_days' => $recency,
                    'frequency' => $frequency,
                    'monetary' => $monetary,
                    'avg_days_between' => $avgDays,
                    'first_purchase_at' => $first,
                    'last_purchase_at' => $last,
                    'purchase_count_30d' => (int) ($w30->c30 ?? 0),
                    'purchase_count_90d' => (int) ($w90->c90 ?? 0),
                    'monetary_30d' => round((float) ($w30->m30 ?? 0), 2),
                    'monetary_90d' => round((float) ($w90->m90 ?? 0), 2),
                    'avg_order_value' => $frequency > 0 ? round($monetary / $frequency, 2) : 0,
                    'computed_at' => $now,
                ]
            );
            $processed++;
        }

        if ($seenPhones !== []) {
            ShopCustomerMetric::query()
                ->where('atelier_id', $atelierId)
                ->whereNotIn('phone', array_keys($seenPhones))
                ->delete();
        } else {
            ShopCustomerMetric::query()->where('atelier_id', $atelierId)->delete();
        }

        return ['processed' => $processed];
    }
}
