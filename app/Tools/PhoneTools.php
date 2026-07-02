<?php

namespace App\Tools;

class PhoneTools
{
    /**
     * نرمال‌سازی شماره موبایل ایران به فرمت ۱۱ رقمی (۰۹xxxxxxxxx).
     */
    public static function normalizeIranPhone($phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $phone) ?: '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '98') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 2);
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            $digits = '0'.$digits;
        }

        return $digits;
    }

    public static function isValidIranMobile(?string $phone): bool
    {
        return is_string($phone) && preg_match('/^09\d{9}$/', $phone) === 1;
    }
}
