<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use App\Tools\PriceTools;

class ProducedGood extends Model
{
    public const DEFAULT_DISPLAY_ORDER = 50;

    public const UNIT_KG = 'kg';

    public const UNIT_PIECE = 'piece';

    /** لایه‌ها مثل ساندویچ از نمای کنار روی هم می‌نشینند */
    public const LAYER_COMPOSE_SIDE = 'side';

    /** لایه‌ها مثل پیتزا از نمای بالا دقیقاً روی هم می‌افتند */
    public const LAYER_COMPOSE_OVERLAY = 'overlay';

    protected $fillable = [
        'atelier_id',
        'name',
        'unit_type',
        'is_final',
        'display_order',
        'sale_price',
        'original_sale_price',
        'markup_percent',
        'round_sale_price',
        'note',
        'layer_order',
        'layer_compose',
        'cover_image_path',
    ];

    protected $casts = [
        'layer_order' => 'array',
        'sale_price' => 'decimal:2',
        'original_sale_price' => 'decimal:2',
        'markup_percent' => 'decimal:2',
        'round_sale_price' => 'boolean',
        'is_final' => 'boolean',
        'display_order' => 'integer',
    ];

    public function getCoverImageUrlAttribute(): ?string
    {
        $path = $this->attributes['cover_image_path'] ?? null;
        if (! $path) {
            return null;
        }

        return \Illuminate\Support\Facades\Storage::url($path);
    }

    /**
     * عکس نمایشی لیست/سبد: کلی کالا، وگرنه لایهٔ مواد.
     */
    public function displayImageUrl(): ?string
    {
        $cover = trim((string) ($this->cover_image_url ?? ''));
        if ($cover !== '') {
            return $cover;
        }

        try {
            $fields = app(\App\Services\ThreedMenuService::class)->catalogFields($this);
        } catch (\Throwable $e) {
            return null;
        }

        $layers = array_values(array_filter((array) ($fields['layers'] ?? []), 'is_array'));
        for ($i = count($layers) - 1; $i >= 0; $i--) {
            $url = trim((string) ($layers[$i]['image_url'] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }
        foreach ((array) ($fields['ingredients'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $url = trim((string) ($row['image_url'] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }

        return null;
    }

    public static function hasIsFinalColumn(): bool
    {
        return Schema::hasColumn('produced_goods', 'is_final');
    }

    /**
     * فقط کالاهای نهایی برای منو / فروش آنلاین / صندوق.
     */
    public function scopeForSaleCatalog(Builder $query): Builder
    {
        if (! static::hasIsFinalColumn()) {
            return $query;
        }

        return $query->where('is_final', true);
    }

    /**
     * کالای نهایی (قابل فروش در منو) در مقابل نیمه‌ساخته.
     * بدون ستون، همه نهایی حساب می‌شوند.
     */
    public function isFinalProduct(): bool
    {
        if (! static::hasIsFinalColumn()) {
            return true;
        }

        $value = $this->attributes['is_final'] ?? true;

        return $value === true || $value === 1 || $value === '1';
    }

    public static function layerComposeModes(): array
    {
        return [self::LAYER_COMPOSE_SIDE, self::LAYER_COMPOSE_OVERLAY];
    }

    public function layerCompose(): string
    {
        $mode = (string) ($this->getAttributes()['layer_compose'] ?? self::LAYER_COMPOSE_SIDE);

        return in_array($mode, self::layerComposeModes(), true)
            ? $mode
            : self::LAYER_COMPOSE_SIDE;
    }

    protected $appends = [
        'sale_price_mode',
        'unit_type',
        'cover_image_url',
    ];

    public function getUnitTypeAttribute(): string
    {
        return $this->unitType();
    }

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

    /**
     * واحد کالا: kg یا piece. رکوردهای قدیمی کیلو حساب می‌شوند.
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
            return PriceTools::roundSalePrice($amount, $this->atelier_id !== null ? (int) $this->atelier_id : null);
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

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'category_produced_good')->withTimestamps();
    }
}
