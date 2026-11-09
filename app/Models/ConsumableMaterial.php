<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsumableMaterial extends Model
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
