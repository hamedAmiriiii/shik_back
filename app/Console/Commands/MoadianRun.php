<?php

namespace App\Console\Commands;

use App\Models\MoadianShopSetting;
use App\Services\Moadian\MoadianRunner;
use Illuminate\Console\Command;

class MoadianRun extends Command
{
    protected $signature = 'moadian:run {--atelier= : فقط یک فروشگاه}';

    protected $description = 'سامانه مؤدیان: جمع‌آوری فروش‌ها، ارسال صورتحساب‌ها و استعلام وضعیت';

    public function handle()
    {
        if (! MoadianShopSetting::tableReady()) {
            return 0;
        }

        $query = MoadianShopSetting::query()->where('enabled', true);
        if ($this->option('atelier')) {
            $query->where('atelier_id', (int) $this->option('atelier'));
        }

        foreach ($query->orderBy('id')->get() as $settings) {
            $report = MoadianRunner::runShop($settings);
            $line = "atelier={$settings->atelier_id} ok=".($report['ok'] ? '1' : '0')
                .' collect='.json_encode($report['collect'])
                .' send='.json_encode($report['send'])
                .' inquiry='.json_encode($report['inquiry']);
            if ($report['message']) {
                $line .= ' message='.$report['message'];
            }
            $this->line($line);
        }

        return 0;
    }
}
