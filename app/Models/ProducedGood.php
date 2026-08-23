<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProducedGood extends Model
{
    protected $fillable = [
        'atelier_id',
        'name',
        'sale_price',
        'note',
    ];

    protected $casts = [
        'sale_price' => 'decimal:2',
    ];

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(ProducedGoodIngredient::class);
    }

    public function productions(): HasMany
    {
        return $this->hasMany(Production::class);
    }
}
