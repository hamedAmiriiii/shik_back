<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryWasteConsumption extends Model
{
    protected $fillable = [
        'inventory_waste_id',
        'raw_material_lot_id',
        'production_id',
        'quantity_kg',
        'price_per_kg',
        'cost',
    ];

    protected $casts = [
        'quantity_kg' => 'decimal:3',
        'price_per_kg' => 'decimal:2',
        'cost' => 'decimal:2',
    ];

    public function waste(): BelongsTo
    {
        return $this->belongsTo(InventoryWaste::class, 'inventory_waste_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(RawMaterialLot::class, 'raw_material_lot_id');
    }

    public function production(): BelongsTo
    {
        return $this->belongsTo(Production::class);
    }
}
