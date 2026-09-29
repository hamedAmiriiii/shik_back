<?php

namespace App\Services\Moadian;

class MoadianTaxId
{
    private const D = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
        [1, 2, 3, 4, 0, 6, 7, 8, 9, 5],
        [2, 3, 4, 0, 1, 7, 8, 9, 5, 6],
        [3, 4, 0, 1, 2, 8, 9, 5, 6, 7],
        [4, 0, 1, 2, 3, 9, 5, 6, 7, 8],
        [5, 9, 8, 7, 6, 0, 4, 3, 2, 1],
        [6, 5, 9, 8, 7, 1, 0, 4, 3, 2],
        [7, 6, 5, 9, 8, 2, 1, 0, 4, 3],
        [8, 7, 6, 5, 9, 3, 2, 1, 0, 4],
        [9, 8, 7, 6, 5, 4, 3, 2, 1, 0],
    ];

    private const P = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
        [1, 5, 7, 6, 2, 8, 3, 0, 9, 4],
        [5, 8, 0, 3, 7, 9, 6, 1, 4, 2],
        [8, 9, 1, 6, 0, 4, 3, 5, 2, 7],
        [9, 4, 5, 3, 1, 2, 6, 8, 7, 0],
        [4, 2, 8, 6, 5, 7, 3, 9, 0, 1],
        [2, 7, 9, 3, 8, 0, 6, 4, 1, 5],
        [7, 0, 4, 6, 9, 1, 3, 2, 5, 8],
    ];

    private const INV = [0, 4, 3, 2, 1, 5, 6, 7, 8, 9];

    /**
     * شمارهٔ منحصربه‌فرد مالیاتی (۲۲ کاراکتر).
     *
     * @param  int  $timestampSeconds  زمان صدور (یونیکس، ثانیه)
     */
    public static function generate(string $memoryId, int $timestampSeconds, int $serial): string
    {
        $memoryId = strtoupper(trim($memoryId));
        $days = intdiv($timestampSeconds, 86400);

        $decimal = self::numericMemoryId($memoryId)
            .str_pad((string) $days, 6, '0', STR_PAD_LEFT)
            .str_pad((string) $serial, 12, '0', STR_PAD_LEFT);

        return strtoupper(
            $memoryId
            .str_pad(dechex($days), 5, '0', STR_PAD_LEFT)
            .str_pad(dechex($serial), 10, '0', STR_PAD_LEFT)
            .self::checksum($decimal)
        );
    }

    /** شمارهٔ داخلی صورتحساب (inno): ده رقم هگز سریال */
    public static function inno(int $serial): string
    {
        return strtoupper(str_pad(dechex($serial), 10, '0', STR_PAD_LEFT));
    }

    public static function checksum(string $number): int
    {
        $c = 0;
        $len = strlen($number);
        for ($i = 0; $i < $len; $i++) {
            $digit = (int) $number[$len - $i - 1];
            $c = self::D[$c][self::P[($i + 1) % 8][$digit]];
        }

        return self::INV[$c];
    }

    private static function numericMemoryId(string $memoryId): string
    {
        $out = '';
        foreach (str_split($memoryId) as $char) {
            $out .= ctype_digit($char) ? $char : (string) ord($char);
        }

        return $out;
    }
}
