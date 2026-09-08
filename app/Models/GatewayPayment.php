<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatewayPayment extends Model
{
    public const TYPE_SMS_PACKAGE = 'sms_package';

    public const TYPE_SHOP_PLAN = 'shop_plan';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    public const GATEWAY_ZARINPAL = 'zarinpal';

    protected $fillable = [
        'atelier_id',
        'user_id',
        'type',
        'item_id',
        'amount_rial',
        'description',
        'authority',
        'ref_id',
        'status',
        'gateway',
        'return_url',
        'meta',
        'paid_at',
    ];

    protected $casts = [
        'amount_rial' => 'integer',
        'item_id' => 'integer',
        'meta' => 'array',
        'paid_at' => 'datetime',
    ];

    public function atelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
