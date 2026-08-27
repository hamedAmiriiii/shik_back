<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Tools\PriceTools;

class ProducedGood extends Model
{
    protected $fillable = [
        'atelier_id',
        'name',
        'sale_price',
        'markup_percent',
        'round_sale_price',
        'note',
    ];

    protected $casts = [
        'sale_price' => 'decimal:2',
        'markup_percent' => 'decimal:2',
        'round_sale_price' => 'boolean',
    ];

    protected $appends = [
        'sale_price_mode',
    ];

    /**
     * فیلدهای محاسباتی که ستون جدول نیستند و نباید با save نوشته شوند.
     */
    private static $computedAttributes = [
        'quantity_kg',
        'total_cost',
        'cost_per_kg',
        'profit_per_kg',
        'profit_percent',
        'stock_kg',
        'ingredient_costs',
        'stock_sufficient',
        'shortages',
    ];

    public function getDirty()
    {
        $dirty = parent::getDirty();
        foreach (self::$computedAttributes as $key) {
            unset($dirty[$key]);
        }

        return $dirty;
    }

    public function getSalePriceModeAttribute(): string
    {
        return $this->usesMarkup() ? 'percent' : 'manual';
    }

    public function usesMarkup(): bool
    {
        return $this->markup_percent !== null && $this->markup_percent !== '';
    }

    public function salePriceFromCost(float $costPerKg): float
    {
        return $this->normalizeSalePrice($costPerKg * (1 + ((float) $this->markup_percent / 100)));
    }

    public function normalizeSalePrice(float $amount): float
    {
        $amount = round($amount, 2);
        if ($this->round_sale_price) {
            return PriceTools::roundSalePrice($amount);
        }

        return $amount;
    }

    public function syncSalePriceFromCost(float $costPerKg): bool
    {
        if (! $this->usesMarkup()) {
            return false;
        }

        return $this->persistSalePrice($this->salePriceFromCost($costPerKg));
    }

    public function syncRoundedSalePrice(): bool
    {
        if (! $this->round_sale_price) {
            return false;
        }

        return $this->persistSalePrice($this->normalizeSalePrice((float) $this->sale_price));
    }

    private function persistSalePrice(float $price): bool
    {
        if (round((float) $this->sale_price, 2) === $price) {
            return false;
        }

        $this->sale_price = $price;
        $this->save();

        return true;
    }

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
