<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'title',
        'unit_price',
        'quantity',
        'unit_type',
        'stock_target',
        'total',
        'vat_mode',
        'vat_percent',
        'vat_amount',
        'discount_amount',
        'sort_order',
    ];

    public const STOCK_PRODUCT = 'product';

    public const STOCK_CONSUMABLE = 'consumable';

    public const STOCK_RAW = 'raw';

    /**
     * @return list<string>
     */
    public static function stockTargets(): array
    {
        return [self::STOCK_PRODUCT, self::STOCK_CONSUMABLE, self::STOCK_RAW];
    }

    /**
     * نوع انبار این قلم؛ اگر روی قلم نباشد از نوع کل فاکتور (فاکتورهای قدیمی) ارث می‌برد.
     */
    public function resolvedStockTarget(?string $invoiceTarget = null): ?string
    {
        $own = is_string($this->stock_target) ? trim($this->stock_target) : '';
        if (in_array($own, self::stockTargets(), true)) {
            return $own;
        }

        $fallback = is_string($invoiceTarget) ? trim($invoiceTarget) : '';
        if (in_array($fallback, self::stockTargets(), true)) {
            return $fallback;
        }

        return null;
    }

    protected $casts = [
        'unit_price' => 'decimal:2',
        'quantity' => 'decimal:3',
        'total' => 'decimal:2',
        'vat_percent' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function rawMaterialLots(): HasMany
    {
        return $this->hasMany(RawMaterialLot::class);
    }
}
