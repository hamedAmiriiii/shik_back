<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class ShopPartner extends Model
{
    protected $fillable = [
        'atelier_id',
        'name',
        'phone',
        'capital_amount',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'capital_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'atelier_id' => 'integer',
    ];

    public static function tableReady(): bool
    {
        return Schema::hasTable('shop_partners');
    }

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function settlementLines(): HasMany
    {
        return $this->hasMany(ShopPartnerSettlementLine::class, 'partner_id');
    }

    public function scopeForAtelier($query, int $atelierId)
    {
        return $query->where('atelier_id', $atelierId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
