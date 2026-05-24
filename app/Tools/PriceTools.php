<?php

namespace App\Tools;

class PriceTools
{
    /**
     * گرد کردن قیمت فروش: یکان، دهگان و صدگان صفر (نزدیک‌ترین هزار تومان).
     */
    public static function roundSalePrice(float $amount): float
    {
        return self::roundToThousand($amount);
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
}
