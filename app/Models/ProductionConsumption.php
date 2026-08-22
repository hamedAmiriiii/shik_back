<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionConsumption extends Model
{
    protected $fillable = [
        'production_id',
        'raw_material_id',
        'raw_material_lot_id',
        'quantity_kg',
        'price_per_kg',
        'cost',
    ];

    protected $casts = [
        'quantity_kg' => 'decimal:3',
        'price_per_kg' => 'decimal:2',
        'cost' => 'decimal:2',
    ];

    public function production(): BelongsTo
    {
        return $this->belongsTo(Production::class);
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(RawMaterialLot::class, 'raw_material_lot_id');
    }
}
