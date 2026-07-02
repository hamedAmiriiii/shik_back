<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmsPackage extends Model
{
    protected $fillable = [
        'name',
        'sms_count',
        'price_rial',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'sms_count' => 'integer',
        'price_rial' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(SmsPackageOrder::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
