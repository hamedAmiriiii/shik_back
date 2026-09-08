<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPlanOrder extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'product_plan_id',
        'product_slug',
        'email',
        'phone',
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
        'product_plan_id' => 'integer',
        'amount_rial' => 'integer',
        'meta' => 'array',
        'paid_at' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductPlan::class, 'product_plan_id');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
