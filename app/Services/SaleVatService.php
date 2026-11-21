<?php

namespace App\Services;

use App\Models\Setting;

/**
 * ارزش افزوده خروجی فروش (حساب ۲۱۳۰۱).
 * MVP: قیمت فروشگاه شامل مالیات فرض می‌شود؛ مالیات از مبلغ ناخالص جدا می‌شود.
 */
class SaleVatService
{
    public const SETTING_ENABLED = 'sale_vat_enabled';

    public const SETTING_RATE = 'sale_vat_rate';

    public const DEFAULT_RATE = 10.0;

    public static function enabledForAtelier(int $atelierId): bool
    {
        if ($atelierId <= 0) {
            return false;
        }

        $raw = Setting::query()
            ->where('atelier_id', $atelierId)
            ->where('key', self::SETTING_ENABLED)
            ->value('value');

        return $raw === '1' || $raw === 1 || $raw === true || $raw === 'true';
    }

    public static function rateForAtelier(int $atelierId): float
    {
        if ($atelierId <= 0) {
            return self::DEFAULT_RATE;
        }

        $raw = Setting::query()
            ->where('atelier_id', $atelierId)
            ->where('key', self::SETTING_RATE)
            ->value('value');
        if ($raw === null || $raw === '') {
            return self::DEFAULT_RATE;
        }

        $rate = round((float) $raw, 2);
        if ($rate < 0 || $rate > 100) {
            return self::DEFAULT_RATE;
        }

        return $rate;
    }

    /**
     * جداسازی مالیات از مبلغ ناخالص (قیمت شامل VAT).
     *
     * @return array{gross: float, net: float, vat: float, rate: float}
     */
    public static function splitGross(int $atelierId, float $gross): array
    {
        $gross = round(max(0, $gross), 2);
        $empty = [
            'gross' => $gross,
            'net' => $gross,
            'vat' => 0.0,
            'rate' => 0.0,
        ];

        if ($gross < 0.01 || ! self::enabledForAtelier($atelierId)) {
            return $empty;
        }

        $rate = self::rateForAtelier($atelierId);
        if ($rate < 0.01) {
            return $empty;
        }

        $vat = round($gross * $rate / (100 + $rate), 2);
        if ($vat > $gross) {
            $vat = $gross;
        }
        $net = round($gross - $vat, 2);

        return [
            'gross' => $gross,
            'net' => $net,
            'vat' => $vat,
            'rate' => $rate,
        ];
    }
}
