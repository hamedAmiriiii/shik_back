<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Morilog\Jalali\Jalalian;

class ReturnedProduct extends Model
{
    use HasFactory;

    protected $fillable = ['product_id', 'sale_price'];

    protected $casts = [
        'sale_price' => 'decimal:2',
    ];

    public function getCreatedAtAttribute($value): string
    {
        return Jalalian::fromDateTime($value)->format('Y-m-d H:i:s');
    }

    public function getUpdatedAtAttribute($value): string
    {
        return Jalalian::fromDateTime($value)->format('Y-m-d H:i:s');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}

