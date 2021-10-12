<?php


namespace App\Models;


class StatusEnum
{
    public const STATUS = [1 => 'در انتظار بررسی', 2 => 'تایید شده', 3 => 'رد شده'];

    public const STATUS_KEYS = ['در انتظار بررسی' => 1, 'تایید شده' => 2, 'رد شده' => 3];
}
