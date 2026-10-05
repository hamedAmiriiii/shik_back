<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * تنظیمات سراسری سیستم بازاریاب‌ها (مستقل از تنظیمات فروشگاه‌ها).
 */
class MarketingSetting extends Model
{
    public const DEFAULT_COMMISSION_PERCENT = 'default_commission_percent';

    /** چند روز بعد از کلیک روی لینک، ثبت‌نام هنوز به نام بازاریاب ثبت می‌شود */
    public const ATTRIBUTION_DAYS = 'attribution_days';

    public const DEFAULTS = [
        self::DEFAULT_COMMISSION_PERCENT => '10',
        self::ATTRIBUTION_DAYS => '60',
    ];

    protected $fillable = ['key', 'value'];

    public static function value(string $key): ?string
    {
        $row = static::query()->where('key', $key)->first();
        if ($row) {
            return $row->value;
        }

        return self::DEFAULTS[$key] ?? null;
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function defaultCommissionPercent(): float
    {
        return max(0.0, min(100.0, (float) static::value(self::DEFAULT_COMMISSION_PERCENT)));
    }

    public static function attributionDays(): int
    {
        return max(1, (int) static::value(self::ATTRIBUTION_DAYS));
    }

    /** @return array{default_commission_percent: float, attribution_days: int} */
    public static function allForApi(): array
    {
        return [
            'default_commission_percent' => static::defaultCommissionPercent(),
            'attribution_days' => static::attributionDays(),
        ];
    }
}
