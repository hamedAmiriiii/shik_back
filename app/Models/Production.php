<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Production extends Model
{
    protected $fillable = [
        'atelier_id',
        'produced_good_id',
        'quantity_kg',
        'remaining_kg',
        'total_cost',
        'cost_per_kg',
        'note',
    ];

    protected $casts = [
        'quantity_kg' => 'decimal:3',
        'remaining_kg' => 'decimal:3',
        'total_cost' => 'decimal:2',
        'cost_per_kg' => 'decimal:2',
    ];

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function producedGood(): BelongsTo
    {
        return $this->belongsTo(ProducedGood::class);
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(ProductionConsumption::class);
    }
}
