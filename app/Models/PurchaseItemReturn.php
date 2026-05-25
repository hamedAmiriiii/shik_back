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
        'quantity',
        'sale_price',
        'purchase_price',
        'return_sale_total',
        'return_purchase_total',
        'phone',
        'payment_type',
        'credit_used_refund',
        'credit_earned_reversed',
        'size',
        'color',
        'user_name',
        'notes',
    ];

    protected $casts = [
        'sale_price' => 'decimal:2',
        'purchase_price' => 'decimal:2',
        'return_sale_total' => 'decimal:2',
        'return_purchase_total' => 'decimal:2',
        'credit_used_refund' => 'decimal:2',
        'credit_earned_reversed' => 'decimal:2',
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

    public function scopeForAtelier($query, int $atelierId)
    {
        return $query->where('atelier_id', $atelierId);
    }
}
