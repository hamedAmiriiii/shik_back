<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'title',
        'unit_price',
        'quantity',
        'total',
        'sort_order',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'quantity' => 'decimal:3',
        'total' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function rawMaterialLots(): HasMany
    {
        return $this->hasMany(RawMaterialLot::class);
    }
}
