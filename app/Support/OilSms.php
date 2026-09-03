<?php

namespace App\Support;

use App\Tools\PlateTools;
use Carbon\Carbon;
use DateTimeInterface;
use Morilog\Jalali\Jalalian;

class OilSms
{
    /**
     * تاریخ شمسی بدون عنوان (مثلاً 1405/06/12).
     */
    public static function jalaliDate($at = null): string
    {
        $carbon = self::toTehran($at);

        return PlateTools::toEnglishDigits(Jalalian::fromCarbon($carbon)->format('Y/m/d'));
    }

    public static function appendDate(string $message, $at = null): string
    {
        return rtrim($message, "\n")."\n".self::jalaliDate($at);
    }

    private static function toTehran($at): Carbon
    {
        if ($at instanceof Carbon) {
            return $at->copy()->timezone('Asia/Tehran');
        }
        if ($at instanceof DateTimeInterface) {
            return Carbon::instance($at)->timezone('Asia/Tehran');
        }
        if (is_string($at) && trim($at) !== '') {
            return Carbon::parse($at)->timezone('Asia/Tehran');
        }

        return now()->timezone('Asia/Tehran');
    }
}
