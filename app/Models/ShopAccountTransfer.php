<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Morilog\Jalali\Jalalian;

class ShopAccountTransfer extends Model
{
    protected $fillable = [
        'atelier_id',
        'from_shop_account_id',
        'to_shop_account_id',
        'amount',
        'date',
        'title',
        'description',
        'user_name',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function getDateAttribute($value): ?string
    {
        if (! $value) {
            return null;
        }

        return Jalalian::fromDateTime($value)->format('Y-m-d');
    }

    public function getCreatedAtAttribute($value): ?string
    {
        if (! $value) {
            return null;
        }

        return Jalalian::fromCarbon(
            \Carbon\Carbon::parse($value)->setTimezone('Asia/Tehran')
        )->format('Y-m-d H:i:s');
    }

    public function getUpdatedAtAttribute($value): ?string
    {
        if (! $value) {
            return null;
        }

        return Jalalian::fromCarbon(
            \Carbon\Carbon::parse($value)->setTimezone('Asia/Tehran')
        )->format('Y-m-d H:i:s');
    }

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(ShopAccount::class, 'from_shop_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(ShopAccount::class, 'to_shop_account_id');
    }

    public function scopeForAtelier($query, int $atelierId)
    {
        return $query->where('atelier_id', $atelierId);
    }
}
