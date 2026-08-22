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
        'note',
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
