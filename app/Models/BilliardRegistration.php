<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BilliardRegistration extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const PAYMENT_UNPAID = 'unpaid';

    public const PAYMENT_PENDING = 'pending';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_WAIVED = 'waived';

    public const PAYMENT_FAILED = 'failed';

    protected $table = 'billiard_registrations';

    protected $fillable = [
        'tournament_id',
        'player_id',
        'status',
        'payment_status',
        'fee_toman',
        'gateway_payment_id',
        'payment_ref',
        'seed',
        'group_code',
        'bracket_position',
        'final_place',
        'points_awarded',
        'confirmed_at',
        'paid_at',
    ];

    protected $casts = [
        'fee_toman' => 'integer',
        'seed' => 'integer',
        'bracket_position' => 'integer',
        'final_place' => 'integer',
        'points_awarded' => 'integer',
        'confirmed_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(BilliardTournament::class, 'tournament_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(BilliardUser::class, 'player_id');
    }

    public function hasSettledPayment(): bool
    {
        return in_array($this->payment_status, [self::PAYMENT_PAID, self::PAYMENT_WAIVED], true);
    }

    /** ثبت‌نام قطعی در مسابقه فقط بعد از پرداخت (یا رایگان بودن) */
    public function isActiveInTournament(): bool
    {
        return $this->status === self::STATUS_CONFIRMED && $this->hasSettledPayment();
    }

    public function isIncompletePayment(): bool
    {
        return ! in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_REJECTED], true)
            && ! $this->hasSettledPayment();
    }

    public function isEligibleForDraw(): bool
    {
        return $this->isActiveInTournament();
    }

    /**
     * ظرفیت و شمارش بازیکن‌های مسابقه فقط ثبت‌نام‌های پرداخت‌شده/رایگان.
     */
    public function scopeActiveInTournament($query)
    {
        return $query
            ->where('status', self::STATUS_CONFIRMED)
            ->whereIn('payment_status', [self::PAYMENT_PAID, self::PAYMENT_WAIVED]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => (int) $this->id,
            'tournament_id' => (int) $this->tournament_id,
            'player_id' => (int) $this->player_id,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'fee_toman' => (int) $this->fee_toman,
            'seed' => $this->seed !== null ? (int) $this->seed : null,
            'group_code' => $this->group_code ?: null,
            'bracket_position' => $this->bracket_position !== null ? (int) $this->bracket_position : null,
            'final_place' => $this->final_place !== null ? (int) $this->final_place : null,
            'points_awarded' => (int) $this->points_awarded,
            'confirmed_at' => optional($this->confirmed_at)->toIso8601String(),
            'paid_at' => optional($this->paid_at)->toIso8601String(),
            'player' => $this->relationLoaded('player') && $this->player
                ? $this->player->toPublicArray()
                : null,
            'tournament' => $this->relationLoaded('tournament') && $this->tournament
                ? $this->tournament->toApiArray()
                : null,
        ];
    }
}
