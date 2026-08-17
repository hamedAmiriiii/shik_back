<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopReferral extends Model
{
    public const STATUS_REGISTERED = 'registered';

    public const STATUS_PLAN_ACTIVATED = 'plan_activated';

    public const STATUS_REWARDED = 'rewarded';

    protected $fillable = [
        'referrer_user_id',
        'referred_user_id',
        'referred_atelier_id',
        'status',
        'reward_amount',
        'registered_at',
        'plan_activated_at',
        'rewarded_at',
    ];

    protected $casts = [
        'reward_amount' => 'decimal:2',
        'registered_at' => 'datetime',
        'plan_activated_at' => 'datetime',
        'rewarded_at' => 'datetime',
    ];

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function referredAtelier(): BelongsTo
    {
        return $this->belongsTo(Atelier::class, 'referred_atelier_id');
    }

    public function isRewarded(): bool
    {
        return $this->status === self::STATUS_REWARDED;
    }
}
