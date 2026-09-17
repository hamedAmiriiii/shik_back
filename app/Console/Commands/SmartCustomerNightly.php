<?php

namespace App\Console\Commands;

use App\Services\SmartCustomer\SmartCustomerPipeline;
use Illuminate\Console\Command;

class SmartCustomerNightly extends Command
{
    protected $signature = 'smart-customer:nightly {--atelier= : فقط یک فروشگاه}';

    protected $description = 'محاسبه RFM، سگمنت و پیشنهادهای باشگاه هوشمند';

    public function handle(): int
    {
        $atelier = $this->option('atelier');
        if ($atelier !== null && $atelier !== '') {
            $result = SmartCustomerPipeline::runForAtelier((int) $atelier);
            $this->info(json_encode($result, JSON_UNESCAPED_UNICODE));

            return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        $result = SmartCustomerPipeline::runAllEnabledShops();
        $this->info('ateliers='.$result['ateliers']);
        foreach ($result['results'] as $id => $row) {
            $this->line("#{$id} metrics=".($row['metrics']['processed'] ?? 0)
                .' segments='.($row['segments']['processed'] ?? 0)
                .' actions='.($row['actions']['created'] ?? 0));
        }

        return self::SUCCESS;
    }
}
