<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormalInvoiceSellerProfile extends Model
{
    protected $fillable = [
        'atelier_id',
        'legal_name',
        'brand_name',
        'province',
        'city',
        'address',
        'postal_code',
        'phone',
        'economic_code',
        'national_id',
        'registration_number',
        'fixed_notes',
    ];

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }
}
