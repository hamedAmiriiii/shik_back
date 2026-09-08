<?php

namespace App\Console\Commands;

use App\Models\DesktopLicense;
use Illuminate\Console\Command;

class ListDesktopLicensesCommand extends Command
{
    protected $signature = 'desktop-license:list';

    protected $description = 'فهرست لایسنس‌های دسکتاپ';

    public function handle(): int
    {
        $rows = DesktopLicense::query()->orderByDesc('id')->limit(100)->get()->map(function (DesktopLicense $l) {
            $active = $l->activeActivations()->count();

            return [
                $l->id,
                $l->license_key,
                $l->customer_name,
                $active . '/' . $l->max_devices,
                $l->status,
                optional($l->expires_at)->toDateString(),
            ];
        })->all();

        $this->table(
            ['ID', 'Key', 'Customer', 'Devices', 'Status', 'Expires'],
            $rows
        );

        return 0;
    }
}
