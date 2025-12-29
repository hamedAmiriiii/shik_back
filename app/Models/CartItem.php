<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cart_id',
        'product_id',
        'quantity',
        'price',
        'size',
        'color'
    ];

    protected $casts = [
        'cart_id' => 'integer',
        'product_id' => 'integer',
        'quantity' => 'integer',
        'price' => 'decimal:2',
    ];

    /**
     * سبد این آیتم
     */
    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * محصول این آیتم
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * محاسبه مبلغ کل این آیتم
     */
    public function getSubtotalAttribute()
    {
        return $this->quantity * $this->price;
    }
}

