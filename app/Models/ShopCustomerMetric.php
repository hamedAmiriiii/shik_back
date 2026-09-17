<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopCustomerMetric extends Model
{
    protected $table = 'shop_customer_metrics';

    protected $fillable = [
        'atelier_id',
        'phone',
        'recency_days',
        'frequency',
        'monetary',
        'avg_days_between',
        'first_purchase_at',
        'last_purchase_at',
        'purchase_count_30d',
        'purchase_count_90d',
        'monetary_30d',
        'monetary_90d',
        'avg_order_value',
        'computed_at',
    ];

    protected $casts = [
        'monetary' => 'decimal:2',
        'avg_days_between' => 'decimal:2',
        'monetary_30d' => 'decimal:2',
        'monetary_90d' => 'decimal:2',
        'avg_order_value' => 'decimal:2',
        'first_purchase_at' => 'datetime',
        'last_purchase_at' => 'datetime',
        'computed_at' => 'datetime',
    ];
}
