<?php

namespace App\Console\Commands;

use App\Services\DesktopLicense\DesktopLicenseService;
use Illuminate\Console\Command;

class CreateDesktopLicenseCommand extends Command
{
    protected $signature = 'desktop-license:create
        {--customer= : نام مشتری}
        {--phone= : تلفن}
        {--email= : ایمیل}
        {--devices=1 : حداکثر تعداد دستگاه}
        {--years=1 : مدت اشتراک (سال)}
        {--notes= : یادداشت}';

    protected $description = 'صدور لایسنس سالانه Webinoo Desktop';

    public function handle(DesktopLicenseService $service): int
    {
        $license = $service->createLicense([
            'customer_name' => $this->option('customer'),
            'customer_phone' => $this->option('phone'),
            'customer_email' => $this->option('email'),
            'max_devices' => (int) $this->option('devices'),
            'years' => (int) $this->option('years'),
            'notes' => $this->option('notes'),
        ]);

        $this->info('لایسنس ایجاد شد:');
        $this->line('  KEY:      ' . $license->license_key);
        $this->line('  Devices:  ' . $license->max_devices);
        $this->line('  Starts:   ' . optional($license->starts_at)->toDateTimeString());
        $this->line('  Expires:  ' . optional($license->expires_at)->toDateTimeString());
        $this->line('  Customer: ' . ($license->customer_name ?: '-'));

        return 0;
    }
}
