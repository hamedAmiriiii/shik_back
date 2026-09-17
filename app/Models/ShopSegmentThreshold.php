<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopSegmentThreshold extends Model
{
    protected $table = 'shop_segment_thresholds';

    protected $fillable = [
        'atelier_id',
        'metrics_window',
        'vip_max_recency_days',
        'vip_min_frequency',
        'vip_min_monetary',
        'loyal_max_recency_days',
        'loyal_min_frequency',
        'new_max_days_since_first',
        'new_max_frequency',
        'growing_min_purchases_90d',
        'at_risk_recency_multiplier',
        'at_risk_min_recency_days',
        'at_risk_min_frequency',
        'inactive_min_recency_days',
        'churned_min_recency_days',
        'high_value_min_monetary',
        'low_value_max_monetary',
        'near_vip_frequency_gap',
        'action_cooldown_days',
        'winback_credit_amount',
        'winback_revenue_factor',
    ];

    protected $casts = [
        'vip_min_monetary' => 'decimal:2',
        'at_risk_recency_multiplier' => 'decimal:2',
        'high_value_min_monetary' => 'decimal:2',
        'low_value_max_monetary' => 'decimal:2',
        'winback_credit_amount' => 'decimal:2',
        'winback_revenue_factor' => 'decimal:2',
    ];
}
