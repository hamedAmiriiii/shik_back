<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Morilog\Jalali\Jalalian;

class PurchasedProduct extends Model
{
    use HasFactory;

    protected $fillable = ['product_id', 'quantity', 'purchase_price', 'phone', 'total_amount', 'credit_used', 'credit_earned'];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'credit_used' => 'decimal:2',
        'credit_earned' => 'decimal:2',
    ];

    public function getCreatedAtAttribute($value): string
    {
        return Jalalian::fromDateTime($value)->format('Y-m-d H:i:s');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
