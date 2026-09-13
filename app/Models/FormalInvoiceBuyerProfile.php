<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormalInvoiceBuyerProfile extends Model
{
    protected $fillable = [
        'atelier_id',
        'phone',
        'full_name',
        'province',
        'city',
        'address',
        'postal_code',
        'economic_code',
        'national_id',
        'registration_number',
    ];

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }
}
