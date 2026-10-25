<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopAccountBalanceAdjustment extends Model
{
    protected $fillable = [
        'atelier_id',
        'shop_account_id',
        'user_id',
        'user_name',
        'amount',
        'balance_before',
        'balance_after',
        'date',
        'description',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'date' => 'date',
        'atelier_id' => 'integer',
        'shop_account_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function shopAccount(): BelongsTo
    {
        return $this->belongsTo(ShopAccount::class, 'shop_account_id');
    }

    public function scopeForAtelier($query, int $atelierId)
    {
        return $query->where('atelier_id', $atelierId);
    }
}
