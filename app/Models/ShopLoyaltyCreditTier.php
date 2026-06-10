<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopLoyaltyCreditTier extends Model
{
    public const MAX_TIERS_PER_SHOP = 5;

    protected $fillable = [
        'atelier_id',
        'sort_order',
        'max_amount',
        'percent',
    ];

    protected $casts = [
        'max_amount' => 'decimal:2',
        'percent' => 'decimal:2',
    ];

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }
}
