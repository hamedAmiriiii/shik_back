<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserCreditGrant extends Model
{
    public const SOURCE_MANUAL = 'manual';

    public const TYPE_REGULAR = 'regular';

    public const TYPE_INSTALLMENT = 'installment';

    protected $fillable = [
        'atelier_id',
        'phone',
        'credit_type',
        'amount',
        'source',
        'purchase_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];
}
