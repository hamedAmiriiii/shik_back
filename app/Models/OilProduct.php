<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OilProduct extends Model
{
    public const KIND_OIL = 'oil';

    public const KIND_AIR_FILTER = 'air_filter';

    public const KIND_OIL_FILTER = 'oil_filter';

    protected $fillable = [
        'atelier_id',
        'kind',
        'name',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * @return array<string, string>
     */
    public static function kinds(): array
    {
        return [
            self::KIND_OIL => 'روغن',
            self::KIND_AIR_FILTER => 'فیلتر هوا',
            self::KIND_OIL_FILTER => 'فیلتر روغن',
        ];
    }

    public static function kindLabel(string $kind): string
    {
        return self::kinds()[$kind] ?? $kind;
    }

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function visitItems(): HasMany
    {
        return $this->hasMany(OilVisitItem::class, 'oil_product_id');
    }

    public function toApiArray(): array
    {
        return [
            'id' => (int) $this->id,
            'kind' => $this->kind,
            'kind_label' => self::kindLabel((string) $this->kind),
            'name' => $this->name,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
