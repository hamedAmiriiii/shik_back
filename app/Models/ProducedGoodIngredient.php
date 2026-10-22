<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProducedGoodIngredient extends Model
{
    protected $fillable = [
        'produced_good_id',
        'raw_material_id',
        'ingredient_produced_good_id',
        'grams_per_kg',
        'is_removable',
        'is_addable',
    ];

    protected $casts = [
        'grams_per_kg' => 'decimal:3',
        'is_removable' => 'boolean',
        'is_addable' => 'boolean',
    ];

    public function producedGood(): BelongsTo
    {
        return $this->belongsTo(ProducedGood::class);
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }

    public function ingredientProducedGood(): BelongsTo
    {
        return $this->belongsTo(ProducedGood::class, 'ingredient_produced_good_id');
    }

    public function isNestedProducedGood(): bool
    {
        return (int) ($this->ingredient_produced_good_id ?? 0) > 0;
    }
}
