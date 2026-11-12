<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsumableMaterial extends Model
{
    public const UNIT_PIECE = 'piece';

    public const UNIT_KG = 'kg';

    public const UNIT_LITER = 'liter';

    protected $fillable = [
        'atelier_id',
        'name',
        'unit_type',
        'note',
    ];

    public function unitType(): string
    {
        $value = $this->attributes['unit_type'] ?? null;
        if ($value === self::UNIT_KG || $value === self::UNIT_LITER) {
            return $value;
        }

        return self::UNIT_PIECE;
    }

    public function isPieceUnit(): bool
    {
        return $this->unitType() === self::UNIT_PIECE;
    }

    public function unitLabelFa(): string
    {
        if ($this->unitType() === self::UNIT_KG) {
            return 'کیلو';
        }
        if ($this->unitType() === self::UNIT_LITER) {
            return 'لیتر';
        }

        return 'عدد';
    }

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function lots(): HasMany
    {
        return $this->hasMany(ConsumableMaterialLot::class)->orderBy('purchased_at')->orderBy('id');
    }

    public function openLots(): HasMany
    {
        return $this->hasMany(ConsumableMaterialLot::class)
            ->where('remaining', '>', 0)
            ->orderBy('purchased_at')
            ->orderBy('id');
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(ConsumableWithdrawal::class)->orderByDesc('withdrawn_at')->orderByDesc('id');
    }

    public static function normalizeName(?string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '');

        return $name;
    }
}
