<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RawMaterial extends Model
{
    public const UNIT_KG = 'kg';

    public const UNIT_PIECE = 'piece';

    protected $fillable = [
        'atelier_id',
        'name',
        'unit_type',
        'sale_price',
        'note',
        'layer_image_path',
    ];

    protected $appends = [
        'unit_type',
        'layer_image_url',
    ];

    public function getLayerImageUrlAttribute(): ?string
    {
        $path = $this->attributes['layer_image_path'] ?? null;
        if (! $path) {
            return null;
        }

        return \Illuminate\Support\Facades\Storage::url($path);
    }

    public function getUnitTypeAttribute(): string
    {
        return $this->unitType();
    }

    protected $casts = [
        'sale_price' => 'decimal:2',
    ];

    /**
     * واحد ماده: kg یا piece. رکوردهای قدیمی (بدون ستون/مقدار) کیلو حساب می‌شوند.
     */
    public function unitType(): string
    {
        return ($this->attributes['unit_type'] ?? null) === self::UNIT_PIECE
            ? self::UNIT_PIECE
            : self::UNIT_KG;
    }

    public function isPieceUnit(): bool
    {
        return $this->unitType() === self::UNIT_PIECE;
    }

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
