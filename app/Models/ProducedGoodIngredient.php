<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProducedGoodIngredient extends Model
{
    protected $fillable = [
        'produced_good_id',
        'raw_material_id',
        'grams_per_kg',
    ];

    protected $casts = [
        'grams_per_kg' => 'decimal:3',
    ];

    public function producedGood(): BelongsTo
    {
        return $this->belongsTo(ProducedGood::class);
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }
}
