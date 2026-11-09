<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumableWithdrawalSlice extends Model
{
    protected $fillable = [
        'consumable_withdrawal_id',
        'consumable_material_lot_id',
        'quantity',
        'unit_cost',
        'cost',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'cost' => 'decimal:2',
    ];

    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(ConsumableWithdrawal::class, 'consumable_withdrawal_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(ConsumableMaterialLot::class, 'consumable_material_lot_id');
    }
}
