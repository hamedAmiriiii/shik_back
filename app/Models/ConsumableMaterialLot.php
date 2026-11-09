<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsumableMaterialLot extends Model
{
    protected $fillable = [
        'atelier_id',
        'consumable_material_id',
        'quantity',
        'remaining',
        'unit_cost',
        'purchased_at',
        'invoice_id',
        'invoice_item_id',
        'note',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'remaining' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'purchased_at' => 'datetime',
    ];

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(ConsumableMaterial::class, 'consumable_material_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    public function withdrawalSlices(): HasMany
    {
        return $this->hasMany(ConsumableWithdrawalSlice::class);
    }

    public function isUntouched(): bool
    {
        return round((float) $this->remaining, 3) === round((float) $this->quantity, 3);
    }
}
