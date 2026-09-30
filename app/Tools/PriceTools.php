<?php

namespace App\Tools;

use App\Models\Setting;

class PriceTools
{
    /** تنظیم فروشگاه: ۱ = رند قیمت فروش به هزار تومان، ۰ = فقط یکان صفر شود */
    public const ROUND_SALE_PRICE_TO_THOUSAND_KEY = 'round_sale_price_to_thousand';

    /** @var array<int, bool> */
    private static $roundToThousandByAtelier = [];

    /**
     * گرد کردن قیمت فروش طبق تنظیم فروشگاه:
     * فعال → یکان، دهگان و صدگان صفر (نزدیک‌ترین هزار تومان)؛ غیرفعال → فقط یکان صفر (۷۵۶ → ۷۶۰).
     */
    public static function roundSalePrice(float $amount, ?int $atelierId = null): float
    {
        return self::roundsSalePriceToThousand($atelierId)
            ? self::roundToThousand($amount)
            : self::roundToTen($amount);
    }

    /**
     * بدون atelier_id، زمینهٔ فروشگاهِ Setting در همین درخواست استفاده می‌شود.
     */
    public static function roundsSalePriceToThousand(?int $atelierId = null): bool
    {
        $atelierId = $atelierId ?? Setting::contextAtelierId();
        $cacheKey = $atelierId ?? 0;

        if (! array_key_exists($cacheKey, self::$roundToThousandByAtelier)) {
            $value = Setting::query()
                ->where('key', self::ROUND_SALE_PRICE_TO_THOUSAND_KEY)
                ->when(
                    $atelierId !== null,
                    fn ($q) => $q->where('atelier_id', $atelierId),
                    fn ($q) => $q->whereNull('atelier_id')
                )
                ->value('value');

            self::$roundToThousandByAtelier[$cacheKey] = $value === null
                || in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
        }

        return self::$roundToThousandByAtelier[$cacheKey];
    }

    public static function forgetSaleRoundingCache(): void
    {
        self::$roundToThousandByAtelier = [];
    }

    /**
     * گرد کردن مبلغ اعتبار به نزدیک‌ترین هزار تومان.
     */
    public static function roundToThousand(float $amount): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        return (float) (round($amount / 1000) * 1000);
    }

    public static function roundToTen(float $amount): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        return (float) (round($amount / 10) * 10);
    }

    /**
     * مبالغ پرداخت و اعتبار باید تومان کامل باشند (بدون ریال اعشاری).
     */
    public static function roundToman(float $amount): float
    {
        return round($amount, 0);
    }
}
