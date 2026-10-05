<?php

namespace App\Tools;

use Carbon\Carbon;
use Morilog\Jalali\Jalalian;

class JalaliTools
{
    /**
     * تبدیل تاریخ میلادی ذخیره‌شده به شمسی؛ برای مقدار خالی، صفر (0000-00-00) یا خارج از بازه null برمی‌گرداند
     * تا یک ردیف خراب کل لیست را با خطای 500 از کار نیندازد.
     */
    public static function format($value, string $format = 'Y-m-d H:i:s', ?string $timezone = 'Asia/Tehran'): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && str_starts_with($value, '0000-00-00')) {
            return null;
        }

        try {
            $carbon = $value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::parse($value);
            if ($timezone !== null) {
                $carbon = $carbon->setTimezone($timezone);
            }

            return Jalalian::fromCarbon($carbon)->format($format);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
