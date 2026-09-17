<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Morilog\Jalali\Jalalian;

class ShopPartnerSettlement extends Model
{
    protected $fillable = [
        'atelier_id',
        'settled_at',
        'period_from',
        'period_to',
        'net_profit',
        'total_distributed',
        'shop_account_id',
        'user_name',
        'notes',
    ];

    protected $casts = [
        'settled_at' => 'date',
        'period_from' => 'date',
        'period_to' => 'date',
        'net_profit' => 'decimal:2',
        'total_distributed' => 'decimal:2',
        'atelier_id' => 'integer',
        'shop_account_id' => 'integer',
    ];

    protected $appends = [
        'settled_at_jalali',
        'period_from_jalali',
        'period_to_jalali',
    ];

    public static function tableReady(): bool
    {
        return Schema::hasTable('shop_partner_settlements')
            && Schema::hasTable('shop_partner_settlement_lines');
    }

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function shopAccount(): BelongsTo
    {
        return $this->belongsTo(ShopAccount::class, 'shop_account_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ShopPartnerSettlementLine::class, 'settlement_id');
    }

    public function getSettledAtJalaliAttribute(): ?string
    {
        return $this->toJalali($this->settled_at);
    }

    public function getPeriodFromJalaliAttribute(): ?string
    {
        return $this->toJalali($this->period_from);
    }

    public function getPeriodToJalaliAttribute(): ?string
    {
        return $this->toJalali($this->period_to);
    }

    protected function toJalali($value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return Jalalian::fromDateTime($value)->format('Y/m/d');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
