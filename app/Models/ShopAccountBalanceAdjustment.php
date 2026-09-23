<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopAccountBalanceAdjustment extends Model
{
    protected $fillable = [
        'atelier_id',
        'shop_account_id',
        'amount',
        'date',
        'description',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'date' => 'date',
        'atelier_id' => 'integer',
        'shop_account_id' => 'integer',
    ];

    public function shopAccount(): BelongsTo
    {
        return $this->belongsTo(ShopAccount::class, 'shop_account_id');
    }
}
