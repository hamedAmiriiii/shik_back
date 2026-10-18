<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Morilog\Jalali\Jalalian;

class PurchaseItemReturn extends Model
{
    protected $fillable = [
        'atelier_id',
        'purchase_id',
        'purchased_product_id',
        'product_id',
        'produced_good_id',
        'raw_material_id',
        'quantity',
        'sale_price',
        'purchase_price',
        'return_sale_total',
        'return_purchase_total',
        'phone',
        'payment_type',
        'credit_used_refund',
        'credit_earned_reversed',
        'cash_refunded',
        'card_refunded',
        'card_refund_destination',
        'shop_account_id',
        'size',
        'color',
        'user_name',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'sale_price' => 'decimal:2',
        'purchase_price' => 'decimal:2',
        'return_sale_total' => 'decimal:2',
        'return_purchase_total' => 'decimal:2',
        'credit_used_refund' => 'decimal:2',
        'credit_earned_reversed' => 'decimal:2',
        'cash_refunded' => 'decimal:2',
        'card_refunded' => 'decimal:2',
        'shop_account_id' => 'integer',
    ];

    public function getCreatedAtAttribute($value): string
    {
        if (! $value) {
            return null;
        }

        return Jalalian::fromDateTime($value)->format('Y-m-d H:i:s');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function producedGood(): BelongsTo
    {
        return $this->belongsTo(ProducedGood::class);
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }

    public function itemName(): string
    {
        if ($this->relationLoaded('product') && $this->product) {
            return (string) ($this->product->name ?? 'کالا');
        }
        if ($this->relationLoaded('producedGood') && $this->producedGood) {
            return (string) ($this->producedGood->name ?? 'کالای تولیدی');
        }
        if ($this->relationLoaded('rawMaterial') && $this->rawMaterial) {
            return (string) ($this->rawMaterial->name ?? 'مواد اولیه');
        }

        return 'قلم برگشتی';
    }

    public function scopeForAtelier($query, int $atelierId)
    {
        return $query->where('atelier_id', $atelierId);
    }
}
