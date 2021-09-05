<?php


namespace App\Models;


class StatusEnum
{
    public const STATUS = ['در انتظار بررسی', 'تایید شده', 'رد شده'];

    public const STATUS_KEYS = ['در انتظار بررسی' => 1, 'تایید شده' => 2, 'رد شده' => 3];
}
