<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopTable extends Model
{
    protected $fillable = [
        'atelier_id',
        'table_number',
        'label',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'table_number' => 'integer',
    ];

    protected $appends = ['display_name'];

    public function getDisplayNameAttribute(): string
    {
        return $this->label ?: 'میز ' . $this->table_number;
    }

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function pendingOrders(): HasMany
    {
        return $this->hasMany(Purchase::class, 'shop_table_id')
            ->whereIn('payment_type', ['debt'])
            ->where('is_debt_settled', false);
    }
}
