<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * بازاریاب: فقط با شماره موبایل و کد پیامکی وارد می‌شود و با لینک اختصاصی فروشگاه معرفی می‌کند.
 */
class Marketer extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'code',
        'commission_percent',
        'card_number',
        'sheba',
        'is_active',
        'admin_note',
        'last_login_at',
    ];

    protected $casts = [
        'commission_percent' => 'decimal:2',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function tokens(): HasMany
    {
        return $this->hasMany(MarketerToken::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(MarketerVisit::class);
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(MarketerReferral::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(MarketerCommission::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(MarketerPayout::class);
    }

    /** درصد مؤثر: درصد اختصاصی این بازاریاب یا درصد پیش‌فرض تنظیمات */
    public function effectiveCommissionPercent(): float
    {
        if ($this->commission_percent !== null) {
            return (float) $this->commission_percent;
        }

        return MarketingSetting::defaultCommissionPercent();
    }

    /** کد معرف فقط عدد ۴ رقمی (۱۰۰۰ تا ۹۹۹۹) */
    public static function generateUniqueCode(): string
    {
        for ($i = 0; $i < 200; $i++) {
            $code = (string) random_int(1000, 9999);
            if (! static::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('امکان تولید کد معرف یکتا وجود ندارد.');
    }
}
