<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductStockNotifyRequest extends Model
{
    protected $table = 'product_stock_notify_requests';

    protected $fillable = [
        'atelier_id',
        'product_id',
        'phone',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
