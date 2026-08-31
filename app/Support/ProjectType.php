<?php

namespace App\Support;

class ProjectType
{
    public const SHOP = 'shop';

    public const OIL = 'oil';

    public static function normalize(?string $value): string
    {
        return $value === self::OIL ? self::OIL : self::SHOP;
    }

    public static function label(string $value): string
    {
        return self::normalize($value) === self::OIL ? 'تعویض روغن' : 'فروشگاه';
    }
}
