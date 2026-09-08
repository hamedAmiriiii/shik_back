<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopPlan extends Model
{
    protected $fillable = [
        'name',
        'duration_days',
        'price_rial',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'duration_days' => 'integer',
        'price_rial' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
