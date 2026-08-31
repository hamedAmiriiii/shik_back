<?php

namespace App\Console\Commands;

use App\Services\AccountingVoucherService;
use Illuminate\Console\Command;
use RuntimeException;

class AccountingVoucherSelfTest extends Command
{
    protected $signature = 'accounting:voucher-self-test {atelier_id}';

    protected $description = 'سناریوی ۲-۴ نقشه راه: سند متوازن، تکرار، نامتوازن، برگشت';

    public function handle(): int
    {
        $atelierId = (int) $this->argument('atelier_id');
        if ($atelierId <= 0) {
            $this->error('atelier_id نامعتبر است.');

            return 1;
        }

        try {
            $result = AccountingVoucherService::selfTest($atelierId);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $this->info('متوازن: '.($result['balanced_saved'] ? 'بله' : 'خیر'));
        $this->info('تکراری همان id: '.($result['idempotent'] ? 'بله' : 'خیر'));
        $this->info('نامتوازن رد شد: '.($result['unbalanced_rejected'] ? 'بله' : 'خیر'));
        $this->info('برگشت: '.($result['reversed'] ? 'بله' : 'خیر'));

        $ok = $result['balanced_saved']
            && $result['idempotent']
            && $result['unbalanced_rejected']
            && $result['reversed'];

        return $ok ? 0 : 1;
    }
}
