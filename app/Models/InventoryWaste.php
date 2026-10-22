<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryWaste extends Model
{
    public const KIND_RAW = 'raw_material';

    public const KIND_PRODUCED = 'produced_good';

    protected $fillable = [
        'atelier_id',
        'kind',
        'raw_material_id',
        'produced_good_id',
        'quantity_kg',
        'total_cost',
        'note',
        'wasted_at',
    ];

    protected $casts = [
        'quantity_kg' => 'decimal:3',
        'total_cost' => 'decimal:2',
        'wasted_at' => 'datetime',
    ];

    public static function kinds(): array
    {
        return [self::KIND_RAW, self::KIND_PRODUCED];
    }

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }

    public function producedGood(): BelongsTo
    {
        return $this->belongsTo(ProducedGood::class);
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(InventoryWasteConsumption::class);
    }

    public function isRawMaterial(): bool
    {
        return $this->kind === self::KIND_RAW;
    }

    public function isProducedGood(): bool
    {
        return $this->kind === self::KIND_PRODUCED;
    }
}
