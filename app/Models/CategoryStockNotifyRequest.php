<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryStockNotifyRequest extends Model
{
    protected $table = 'category_stock_notify_requests';

    protected $fillable = [
        'atelier_id',
        'category_id',
        'phone',
        'note',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class, 'atelier_id');
    }
}
