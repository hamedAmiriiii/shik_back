<?php

namespace App\Services\Moadian;

/**
 * محاسبهٔ ردیف‌ها و جمع‌های صورتحساب به ریال. مبالغ فروشگاه به تومان ذخیره شده‌اند (×۱۰) و
 * همهٔ مبالغ ریالی به پایین گرد (truncate) می‌شوند.
 *
 * حالت «قیمت شامل مالیات»: مبلغ پرداختی مشتری ثابت می‌ماند و مالیات از داخل آن جدا می‌شود.
 * حالت «قیمت بدون مالیات»: مالیات روی قیمت فروش اضافه می‌شود (فقط در صورتحساب مؤدیان؛ جمع صندوق تغییر نمی‌کند).
 */
class MoadianTaxCalculator
{
    /**
     * @return array{items: array<int, array>, totals: array<string, int>}
     */
    public static function calculate(array $snapshot, bool $priceIncludesVat): array
    {
        $lines = array_values($snapshot['lines']);
        $grossTotal = 0;
        $grossByLine = [];
        foreach ($lines as $i => $line) {
            $gross = (int) floor($line['qty'] * $line['unit_price_toman'] * 10);
            $grossByLine[$i] = $gross;
            $grossTotal += $gross;
        }

        $discountRial = (int) floor(((float) $snapshot['discount_toman']) * 10);
        $discountRial = min($discountRial, $grossTotal);
        $shares = self::allocate($discountRial, $grossByLine);

        $items = [];
        $totals = ['tprdis' => 0, 'tdis' => 0, 'tadis' => 0, 'tvam' => 0, 'todam' => 0, 'tbill' => 0];

        foreach ($lines as $i => $line) {
            $vra = (float) $line['vra'];
            $odr = (float) $line['odr'];
            $divisor = $priceIncludesVat ? (100 + $vra + $odr) / 100 : 1.0;

            $fee = (int) floor(($line['unit_price_toman'] * 10) / $divisor);
            $prdis = (int) floor($line['qty'] * $fee);
            $dis = min($prdis, (int) floor($shares[$i] / $divisor));
            $adis = $prdis - $dis;
            $vam = (int) floor($adis * $vra / 100);
            $odam = (int) floor($adis * $odr / 100);
            $tsstam = $adis + $vam + $odam;

            $items[] = [
                'line_key' => $line['key'],
                'purchased_product_id' => $line['purchased_product_id'] ?? null,
                'sstid' => $line['sstid'],
                'sstt' => $line['title'],
                'mu' => $line['mu'],
                'am' => $line['qty'],
                'fee' => $fee,
                'prdis' => $prdis,
                'dis' => $dis,
                'adis' => $adis,
                'vra' => $vra,
                'vam' => $vam,
                'odt' => $line['odt'] ?? '',
                'odr' => $odr,
                'odam' => $odam,
                'tsstam' => $tsstam,
            ];

            $totals['tprdis'] += $prdis;
            $totals['tdis'] += $dis;
            $totals['tadis'] += $adis;
            $totals['tvam'] += $vam;
            $totals['todam'] += $odam;
        }
        $totals['tbill'] = $totals['tadis'] + $totals['tvam'] + $totals['todam'];

        return ['items' => $items, 'totals' => $totals];
    }

    /**
     * تقسیم تخفیف فاکتور بین ردیف‌ها به نسبت مبلغ؛ باقی‌ماندهٔ گردکردن به بزرگ‌ترین ردیف.
     *
     * @param  int[]  $weights
     * @return int[]
     */
    private static function allocate(int $amount, array $weights): array
    {
        $shares = array_fill_keys(array_keys($weights), 0);
        $total = array_sum($weights);
        if ($amount <= 0 || $total <= 0) {
            return $shares;
        }

        $allocated = 0;
        foreach ($weights as $i => $w) {
            $shares[$i] = (int) floor($amount * $w / $total);
            $allocated += $shares[$i];
        }
        $rest = $amount - $allocated;
        if ($rest > 0) {
            $largest = array_keys($weights, max($weights))[0];
            $shares[$largest] = min($weights[$largest], $shares[$largest] + $rest);
        }

        return $shares;
    }
}
