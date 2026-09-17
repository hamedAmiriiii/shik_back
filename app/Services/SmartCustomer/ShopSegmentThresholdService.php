<?php

namespace App\Services\SmartCustomer;

use App\Models\ShopSegmentThreshold;
use Illuminate\Support\Facades\Schema;

class ShopSegmentThresholdService
{
    public static function tableReady(): bool
    {
        return Schema::hasTable('shop_segment_thresholds');
    }

    public static function defaults(): array
    {
        return [
            'metrics_window' => 'all',
            'vip_max_recency_days' => 30,
            'vip_min_frequency' => 8,
            'vip_min_monetary' => 15000000,
            'loyal_max_recency_days' => 45,
            'loyal_min_frequency' => 4,
            'new_max_days_since_first' => 21,
            'new_max_frequency' => 2,
            'growing_min_purchases_90d' => 3,
            'at_risk_recency_multiplier' => 1.5,
            'at_risk_min_recency_days' => 35,
            'at_risk_min_frequency' => 3,
            'inactive_min_recency_days' => 60,
            'churned_min_recency_days' => 120,
            'high_value_min_monetary' => 10000000,
            'low_value_max_monetary' => 1000000,
            'near_vip_frequency_gap' => 2,
            'action_cooldown_days' => 4,
            'winback_credit_amount' => 100000,
            'winback_revenue_factor' => 0.35,
        ];
    }

    public static function forAtelier(int $atelierId): ShopSegmentThreshold
    {
        $row = ShopSegmentThreshold::query()->where('atelier_id', $atelierId)->first();
        if ($row) {
            return $row;
        }

        return ShopSegmentThreshold::create(array_merge(
            ['atelier_id' => $atelierId],
            self::defaults()
        ));
    }

    public static function updateForAtelier(int $atelierId, array $data): ShopSegmentThreshold
    {
        $allowed = array_keys(self::defaults());
        $payload = array_intersect_key($data, array_flip($allowed));
        $row = self::forAtelier($atelierId);
        $row->fill($payload);
        $row->save();

        return $row->fresh();
    }
}
