<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopPartnerSettlementLine extends Model
{
    protected $fillable = [
        'settlement_id',
        'partner_id',
        'partner_name',
        'capital_amount',
        'share_percent',
        'amount',
    ];

    protected $casts = [
        'settlement_id' => 'integer',
        'partner_id' => 'integer',
        'capital_amount' => 'decimal:2',
        'share_percent' => 'decimal:4',
        'amount' => 'decimal:2',
    ];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(ShopPartnerSettlement::class, 'settlement_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(ShopPartner::class, 'partner_id');
    }
}
