<?php

namespace App\Services\SmartCustomer;

use App\Models\ShopCustomerMetric;
use App\Models\ShopCustomerSegment;
use App\Models\ShopSegmentThreshold;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class CustomerSegmentAssigner
{
    public static function tableReady(): bool
    {
        return Schema::hasTable('shop_customer_segments')
            && Schema::hasTable('shop_customer_metrics');
    }

    /**
     * @return array{processed:int}
     */
    public static function assignForAtelier(int $atelierId): array
    {
        if (! self::tableReady() || $atelierId <= 0) {
            return ['processed' => 0];
        }

        $thresholds = ShopSegmentThresholdService::forAtelier($atelierId);
        $now = Carbon::now('Asia/Tehran');
        $processed = 0;
        $seen = [];

        ShopCustomerMetric::query()
            ->where('atelier_id', $atelierId)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($atelierId, $thresholds, $now, &$processed, &$seen) {
                foreach ($rows as $metric) {
                    /** @var ShopCustomerMetric $metric */
                    $phone = (string) $metric->phone;
                    $seen[$phone] = true;
                    $assigned = self::classify($metric, $thresholds, $now);

                    ShopCustomerSegment::updateOrCreate(
                        ['atelier_id' => $atelierId, 'phone' => $phone],
                        [
                            'primary_segment' => $assigned['primary'],
                            'tags' => $assigned['tags'],
                            'rfm_scores' => $assigned['rfm_scores'],
                        ]
                    );
                    $processed++;
                }
            });

        if ($seen !== []) {
            ShopCustomerSegment::query()
                ->where('atelier_id', $atelierId)
                ->whereNotIn('phone', array_keys($seen))
                ->delete();
        } else {
            ShopCustomerSegment::query()->where('atelier_id', $atelierId)->delete();
        }

        return ['processed' => $processed];
    }

    /**
     * @return array{primary:string,tags:array<int,string>,rfm_scores:array<string,int>}
     */
    public static function classify(ShopCustomerMetric $m, ShopSegmentThreshold $t, Carbon $now): array
    {
        $recency = (int) $m->recency_days;
        $frequency = (int) $m->frequency;
        $monetary = (float) $m->monetary;
        $avgBetween = $m->avg_days_between !== null ? (float) $m->avg_days_between : null;
        $daysSinceFirst = $m->first_purchase_at
            ? (int) Carbon::parse($m->first_purchase_at)->diffInDays($now)
            : 9999;

        $tags = [];
        if ($monetary >= (float) $t->high_value_min_monetary) {
            $tags[] = ShopCustomerSegment::TAG_HIGH_VALUE;
        } elseif ($monetary > 0 && $monetary <= (float) $t->low_value_max_monetary) {
            $tags[] = ShopCustomerSegment::TAG_LOW_VALUE;
        }

        $vipReady = $recency <= (int) $t->vip_max_recency_days
            && $frequency >= (int) $t->vip_min_frequency
            && $monetary >= (float) $t->vip_min_monetary;

        if (! $vipReady
            && $recency <= (int) $t->vip_max_recency_days
            && $frequency >= max(1, (int) $t->vip_min_frequency - (int) $t->near_vip_frequency_gap)
            && $monetary >= (float) $t->vip_min_monetary * 0.7
        ) {
            $tags[] = ShopCustomerSegment::TAG_NEAR_VIP;
        }

        $atRiskThreshold = max(
            (int) $t->at_risk_min_recency_days,
            $avgBetween !== null
                ? (int) ceil($avgBetween * (float) $t->at_risk_recency_multiplier)
                : (int) $t->at_risk_min_recency_days
        );

        if ($frequency >= (int) $t->at_risk_min_frequency
            && $recency >= $atRiskThreshold
            && $recency < (int) $t->churned_min_recency_days
            && $avgBetween !== null
            && $recency <= (int) ceil($avgBetween * 3)
        ) {
            // ready repurchase: late but not dead
        }

        if ($avgBetween !== null
            && $recency >= (int) floor($avgBetween * 0.8)
            && $recency <= (int) ceil($avgBetween * 1.2)
            && $frequency >= 2
        ) {
            $tags[] = ShopCustomerSegment::TAG_READY_REPURCHASE;
        }

        $primary = ShopCustomerSegment::OTHER;

        if ($vipReady) {
            $primary = ShopCustomerSegment::VIP;
        } elseif (
            $frequency >= (int) $t->at_risk_min_frequency
            && $recency >= $atRiskThreshold
            && $recency < (int) $t->inactive_min_recency_days
        ) {
            $primary = ShopCustomerSegment::AT_RISK;
        } elseif ($recency >= (int) $t->churned_min_recency_days) {
            $primary = ShopCustomerSegment::CHURNED;
        } elseif ($recency >= (int) $t->inactive_min_recency_days) {
            $primary = ShopCustomerSegment::INACTIVE;
        } elseif (
            $recency <= (int) $t->loyal_max_recency_days
            && $frequency >= (int) $t->loyal_min_frequency
        ) {
            $primary = ShopCustomerSegment::LOYAL;
        } elseif (
            (int) $m->purchase_count_90d >= (int) $t->growing_min_purchases_90d
            && $recency <= 45
        ) {
            $primary = ShopCustomerSegment::GROWING;
        } elseif (
            $daysSinceFirst <= (int) $t->new_max_days_since_first
            && $frequency <= (int) $t->new_max_frequency
        ) {
            $primary = ShopCustomerSegment::NEW;
        }

        return [
            'primary' => $primary,
            'tags' => array_values(array_unique($tags)),
            'rfm_scores' => self::simpleRfmScores($recency, $frequency, $monetary, $t),
        ];
    }

    /**
     * @return array{R:int,F:int,M:int}
     */
    protected static function simpleRfmScores(int $recency, int $frequency, float $monetary, ShopSegmentThreshold $t): array
    {
        $r = 1;
        if ($recency <= 7) {
            $r = 5;
        } elseif ($recency <= 30) {
            $r = 4;
        } elseif ($recency <= 60) {
            $r = 3;
        } elseif ($recency <= 120) {
            $r = 2;
        }

        $f = 1;
        if ($frequency >= (int) $t->vip_min_frequency) {
            $f = 5;
        } elseif ($frequency >= (int) $t->loyal_min_frequency) {
            $f = 4;
        } elseif ($frequency >= 3) {
            $f = 3;
        } elseif ($frequency >= 2) {
            $f = 2;
        }

        $m = 1;
        if ($monetary >= (float) $t->vip_min_monetary) {
            $m = 5;
        } elseif ($monetary >= (float) $t->high_value_min_monetary) {
            $m = 4;
        } elseif ($monetary >= (float) $t->high_value_min_monetary / 2) {
            $m = 3;
        } elseif ($monetary > (float) $t->low_value_max_monetary) {
            $m = 2;
        }

        return ['R' => $r, 'F' => $f, 'M' => $m];
    }
}
