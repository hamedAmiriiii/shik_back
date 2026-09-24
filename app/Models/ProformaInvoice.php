<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProformaInvoice extends Model
{
    protected $fillable = [
        'atelier_id',
        'phone',
        'discount_amount',
        'total_amount',
        'items',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'items' => 'array',
    ];
}
