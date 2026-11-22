<?php

namespace App\Services;

use App\Tools\PriceTools;

/**
 * تقسیم اعتبار باشگاه بین اقلام فاکتور فقط با مضارب ۱۰۰۰ تومان.
 * باقیماندهٔ کمتر از هزار تومان در حساب مشتری می‌ماند.
 */
class LoyaltyCreditAllocator
{
    /**
     * @param  array<int, float|int>  $lineTotals  مبلغ هر ردیف (فی × تعداد)
     * @return array{shares: array<int, float>, used: float}
     */
    public static function allocate(array $lineTotals, float $creditAvailable): array
    {
        $keys = array_keys($lineTotals);
        $shares = [];
        foreach ($keys as $key) {
            $shares[$key] = 0.0;
        }

        $weights = [];
        $total = 0.0;
        foreach ($lineTotals as $key => $amount) {
            $w = max(0.0, round((float) $amount, 2));
            $weights[$key] = $w;
            $total = round($total + $w, 2);
        }

        $budget = self::floorToThousand(min(max(0.0, $creditAvailable), $total));
        if ($budget < 1000 || $total < 0.01) {
            return ['shares' => $shares, 'used' => 0.0];
        }

        $raw = [];
        foreach ($weights as $key => $w) {
            $raw[$key] = $budget * ($w / $total);
            $cap = self::floorToThousand($w);
            $shares[$key] = min($cap, PriceTools::roundToThousand($raw[$key]));
        }

        $sum = round(array_sum($shares), 2);

        while ($sum > $budget + 0.01) {
            $idx = self::pickReduceIndex($shares, $weights, $raw);
            if ($idx === null) {
                break;
            }
            $shares[$idx] = round($shares[$idx] - 1000, 2);
            $sum = round($sum - 1000, 2);
        }

        while ($sum + 1000 <= $budget + 0.01) {
            $idx = self::pickIncreaseIndex($shares, $weights, $raw);
            if ($idx === null) {
                break;
            }
            $shares[$idx] = round($shares[$idx] + 1000, 2);
            $sum = round($sum + 1000, 2);
        }

        return [
            'shares' => $shares,
            'used' => round(array_sum($shares), 2),
        ];
    }

    public static function floorToThousand(float $amount): float
    {
        if ($amount < 1000) {
            return 0.0;
        }

        return (float) (floor($amount / 1000) * 1000);
    }

    /**
     * @param  array<int, float>  $shares
     * @param  array<int, float>  $weights
     * @param  array<int, float>  $raw
     */
    protected static function pickReduceIndex(array $shares, array $weights, array $raw): ?int
    {
        $best = null;
        $bestWeight = -1.0;
        $bestOvershoot = -INF;

        foreach ($shares as $key => $share) {
            if ($share < 1000) {
                continue;
            }
            $overshoot = $share - ($raw[$key] ?? 0);
            $weight = $weights[$key] ?? 0;
            if (
                $best === null
                || $weight > $bestWeight + 0.01
                || (abs($weight - $bestWeight) < 0.01 && $overshoot > $bestOvershoot)
            ) {
                $best = (int) $key;
                $bestWeight = $weight;
                $bestOvershoot = $overshoot;
            }
        }

        return $best;
    }

    /**
     * @param  array<int, float>  $shares
     * @param  array<int, float>  $weights
     * @param  array<int, float>  $raw
     */
    protected static function pickIncreaseIndex(array $shares, array $weights, array $raw): ?int
    {
        $best = null;
        $bestWeight = -1.0;
        $bestUnder = -INF;

        foreach ($weights as $key => $weight) {
            $cap = self::floorToThousand($weight);
            $share = $shares[$key] ?? 0.0;
            if ($share + 1000 > $cap + 0.01) {
                continue;
            }
            $under = ($raw[$key] ?? 0) - $share;
            if (
                $best === null
                || $weight > $bestWeight + 0.01
                || (abs($weight - $bestWeight) < 0.01 && $under > $bestUnder)
            ) {
                $best = (int) $key;
                $bestWeight = $weight;
                $bestUnder = $under;
            }
        }

        return $best;
    }
}
