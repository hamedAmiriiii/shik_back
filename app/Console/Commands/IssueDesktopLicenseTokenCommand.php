<?php

namespace App\Console\Commands;

use App\Services\DesktopLicense\DesktopLicenseService;
use Illuminate\Console\Command;

class IssueDesktopLicenseTokenCommand extends Command
{
    protected $signature = 'desktop-license:issue-token
        {license_key : کلید لایسنس}
        {machine_id : شناسه دستگاه}
        {--validate : فقط تمدید اعتبار بدون ساخت فعال‌سازی جدید}';

    protected $description = 'صدور توکن امضاشده لایسنس (برای تست یا بازیابی)';

    public function handle(DesktopLicenseService $service): int
    {
        try {
            if ($this->option('validate')) {
                $result = $service->validate($this->argument('license_key'), $this->argument('machine_id'));
            } else {
                $result = $service->activate(
                    $this->argument('license_key'),
                    $this->argument('machine_id'),
                    'cli',
                    PHP_OS_FAMILY
                );
            }
            $this->info('TOKEN:');
            $this->line($result['token']);

            return 0;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return 1;
        }
    }
}
