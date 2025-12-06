<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Morilog\Jalali\Jalalian;

class PurchasedProduct extends Model
{
    use HasFactory;

    protected $fillable = ['purchase_id', 'product_id', 'quantity', 'purchase_price'];

    public function getCreatedAtAttribute($value): string
    {
        return Jalalian::fromDateTime($value)->format('Y-m-d H:i:s');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * سبد خرید این محصول
     */
    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }
}
