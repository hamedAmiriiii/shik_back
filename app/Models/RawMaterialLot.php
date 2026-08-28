<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RawMaterialLot extends Model
{
    protected $fillable = [
        'atelier_id',
        'raw_material_id',
        'quantity_kg',
        'remaining_kg',
        'price_per_kg',
        'purchased_at',
        'note',
        'invoice_id',
        'invoice_item_id',
    ];

    protected $appends = [
        'invoice_link',
        'invoice_amount',
    ];

    protected $casts = [
        'quantity_kg' => 'decimal:3',
        'remaining_kg' => 'decimal:3',
        'price_per_kg' => 'decimal:2',
        'purchased_at' => 'datetime',
    ];

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    /**
     * whole = کل فاکتور | item = یک ردیف فاکتور
     */
    public function getInvoiceLinkAttribute(): ?string
    {
        if (empty($this->attributes['invoice_id'])) {
            return null;
        }

        return empty($this->attributes['invoice_item_id']) ? 'whole' : 'item';
    }

    public function getInvoiceAmountAttribute(): ?float
    {
        $qty = (float) ($this->attributes['quantity_kg'] ?? 0);
        $price = (float) ($this->attributes['price_per_kg'] ?? 0);

        return round($qty * $price, 2);
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(ProductionConsumption::class);
    }

    public function isUntouched(): bool
    {
        return round((float) $this->remaining_kg, 3) === round((float) $this->quantity_kg, 3);
    }
}
