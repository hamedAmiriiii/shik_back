<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RawMaterial extends Model
{
    public const UNIT_KG = 'kg';

    public const UNIT_LITER = 'liter';

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
     * واحد ماده: kg، liter یا piece. رکوردهای قدیمی (بدون ستون/مقدار) کیلو حساب می‌شوند.
     * لیتری از نظر محاسبات مصرف مثل کیلویی است (مقدار فرمول ÷ ۱۰۰۰).
     */
    public function unitType(): string
    {
        $value = $this->attributes['unit_type'] ?? null;
        if ($value === self::UNIT_PIECE) {
            return self::UNIT_PIECE;
        }
        if ($value === self::UNIT_LITER) {
            return self::UNIT_LITER;
        }

        return self::UNIT_KG;
    }

    public function isPieceUnit(): bool
    {
        return $this->unitType() === self::UNIT_PIECE;
    }

    public function isLiterUnit(): bool
    {
        return $this->unitType() === self::UNIT_LITER;
    }

    /** برچسب فارسی واحد برای پیام‌ها و UI */
    public function unitLabelFa(): string
    {
        if ($this->isPieceUnit()) {
            return 'عدد';
        }
        if ($this->isLiterUnit()) {
            return 'لیتر';
        }

        return 'کیلو';
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
