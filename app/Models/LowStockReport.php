<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LowStockReport extends Model
{
    protected $fillable = [
        'atelier_id',
        'calculated_date',
        'calculated_at',
        'payload',
    ];

    protected $casts = [
        'calculated_date' => 'date',
        'calculated_at' => 'datetime',
        'payload' => 'array',
    ];

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }
}
