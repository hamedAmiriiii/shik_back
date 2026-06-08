<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Morilog\Jalali\Jalalian;

class DailyShopReconciliationDeposit extends Model
{
    protected $fillable = [
        'atelier_id',
        'amount',
        'title',
        'description',
        'date',
        'user_name',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function getDateAttribute($value): string
    {
        return Jalalian::fromDateTime($value)->format('Y-m-d');
    }

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }
}
