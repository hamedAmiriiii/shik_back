<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsumableWithdrawal extends Model
{
    protected $fillable = [
        'atelier_id',
        'consumable_material_id',
        'quantity',
        'total_cost',
        'withdrawn_at',
        'note',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'total_cost' => 'decimal:2',
        'withdrawn_at' => 'datetime',
    ];

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(ConsumableMaterial::class, 'consumable_material_id');
    }

    public function slices(): HasMany
    {
        return $this->hasMany(ConsumableWithdrawalSlice::class);
    }
}
