<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = ["name", "price_buy", "quantity", "barcode", "sale_price", "purchase_price", "original_sale_price", "sizes", "colors", "manufacturer_id"];

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'original_sale_price' => 'decimal:2',
        'sizes' => 'array',
        'colors' => 'array',
    ];

    public function scopeFilterByPrice($query, $minPrice, $maxPrice)
    {
        return $query->whereBetween('sale_price', [$minPrice, $maxPrice]);
    }

    /**
     * عکس‌های این محصول
     */
    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('order');
    }

    /**
     * کتگوری‌های این محصول
     */
    public function categories()
    {
        return $this->belongsToMany(Category::class);
    }

    /**
     * تولیدکننده این محصول
     */
    public function manufacturer()
    {
        return $this->belongsTo(Manufacturer::class);
    }
}


