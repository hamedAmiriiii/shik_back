<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class TableOrderItem extends Model
{
    protected $fillable = [
        'table_order_id',
        'product_id',
        'produced_good_id',
        'raw_material_id',
        'item_name',
        'quantity',
        'purchase_price',
        'sale_price',
        'size',
        'color',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'purchase_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
    ];

    private static ?bool $hasCatalogColumns = null;

    /** ستون‌های کالای تولیدی/مواد اولیه (SQL دستی ممکن است هنوز اجرا نشده باشد). */
    public static function hasCatalogColumns(): bool
    {
        if (self::$hasCatalogColumns === null) {
            try {
                self::$hasCatalogColumns = Schema::hasColumn('table_order_items', 'produced_good_id');
            } catch (\Throwable $e) {
                self::$hasCatalogColumns = false;
            }
        }

        return self::$hasCatalogColumns;
    }

    public function tableOrder(): BelongsTo
    {
        return $this->belongsTo(TableOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function displayName(): ?string
    {
        $name = trim((string) ($this->item_name ?? ''));
        if ($name !== '') {
            return $name;
        }

        return $this->product_id ? optional($this->product)->name : null;
    }
}
