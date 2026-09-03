<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OilProduct extends Model
{
    public const KIND_OIL = 'oil';

    public const KIND_GEARBOX_OIL = 'gearbox_oil';

    public const KIND_AIR_FILTER = 'air_filter';

    public const KIND_OIL_FILTER = 'oil_filter';

    protected $fillable = [
        'atelier_id',
        'kind',
        'name',
        'purchase_price',
        'sale_price',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'purchase_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
    ];

    /**
     * @return array<string, string>
     */
    public static function kinds(): array
    {
        return [
            self::KIND_OIL => 'روغن',
            self::KIND_GEARBOX_OIL => 'روغن گیربکس',
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
            'purchase_price' => round((float) $this->purchase_price, 2),
            'sale_price' => round((float) $this->sale_price, 2),
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
