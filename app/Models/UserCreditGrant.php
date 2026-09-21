<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserCreditGrant extends Model
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_CAMPAIGN = 'campaign';

    public const TYPE_REGULAR = 'regular';

    public const TYPE_INSTALLMENT = 'installment';

    protected $fillable = [
        'atelier_id',
        'phone',
        'credit_type',
        'amount',
        'remaining',
        'expires_at',
        'source',
        'purchase_id',
        'campaign_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'remaining' => 'decimal:2',
        'expires_at' => 'datetime',
    ];
}
