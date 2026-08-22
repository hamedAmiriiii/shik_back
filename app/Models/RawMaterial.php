<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RawMaterial extends Model
{
    protected $fillable = [
        'atelier_id',
        'name',
        'note',
    ];

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function lots(): HasMany
    {
        return $this->hasMany(RawMaterialLot::class)->orderBy('purchased_at')->orderBy('id');
    }

    public function openLots(): HasMany
    {
        return $this->hasMany(RawMaterialLot::class)
            ->where('remaining_kg', '>', 0)
            ->orderBy('purchased_at')
            ->orderBy('id');
    }

    public function recipeLines(): HasMany
    {
        return $this->hasMany(ProducedGoodIngredient::class);
    }
}
