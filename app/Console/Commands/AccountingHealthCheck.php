<?php

namespace App\Console\Commands;

use App\Models\AccountingAccount;
use App\Models\AccountingVoucher;
use App\Services\AccountingReportService;
use App\Services\ChartOfAccountsSeeder;
use App\Services\ShopBackupTables;
use App\Services\ShopPermissionCatalog;
use Illuminate\Console\Command;
use RuntimeException;

class AccountingHealthCheck extends Command
{
    protected $signature = 'accounting:health-check {atelier_id}';

    protected $description = 'کنترل مرحله ۶: جداول، بذر، تراز، پرمیشن، بکاپ';

    public function handle(): int
    {
        $atelierId = (int) $this->argument('atelier_id');
        if ($atelierId <= 0) {
            $this->error('atelier_id نامعتبر است.');

            return 1;
        }

        $checks = [];
        $checks[] = $this->check(
            'جدول کدینگ',
            AccountingAccount::tableReady(),
            'accounting_accounts موجود است',
            'migration یا SQL مرحله ۱ را اجرا کنید'
        );
        $checks[] = $this->check(
            'جداول سند',
            AccountingVoucher::tablesReady(),
            'accounting_vouchers و accounting_lines موجودند',
            'migration یا SQL مرحله ۲ را اجرا کنید'
        );

        $seeded = false;
        if (AccountingAccount::tableReady()) {
            ChartOfAccountsSeeder::ensureForAtelier($atelierId);
            $codes = AccountingAccount::query()
                ->forAtelier($atelierId)
                ->whereIn('code', [
                    ChartOfAccountsSeeder::CODE_TILL,
                    ChartOfAccountsSeeder::CODE_EQUITY,
                    ChartOfAccountsSeeder::CODE_REVENUE,
                    ChartOfAccountsSeeder::CODE_AR,
                ])
                ->pluck('code')
                ->all();
            $seeded = count($codes) === 4;
        }
        $checks[] = $this->check(
            'بذر درخت',
            $seeded,
            'حساب‌های قفل‌شده (۱۱۱۰۱، ۳۱۱، ۴۱۱، ۱۱۲۰۱) موجودند',
            'ChartOfAccountsSeeder برای این فروشگاه اجرا نشد'
        );

        $permOk = in_array('accounting', ShopPermissionCatalog::keys(), true);
        $checks[] = $this->check(
            'پرمیشن',
            $permOk,
            'کلید accounting در کاتالوگ است',
            'ShopPermissionCatalog کلید accounting ندارد'
        );

        $backupNames = array_column(ShopBackupTables::definitions(), 'name');
        $backupOk = ShopBackupTables::VERSION >= 2
            && in_array('accounting_accounts', $backupNames, true)
            && in_array('accounting_vouchers', $backupNames, true)
            && in_array('accounting_lines', $backupNames, true);
        $checks[] = $this->check(
            'بکاپ',
            $backupOk,
            'نسخه ۲ و سه جدول دفتر در تعریف پشتیبان هستند',
            'ShopBackupTables جداول حسابداری را ندارد'
        );

        $tbOk = false;
        $tbDetail = 'جداول سند آماده نیست';
        $bsOk = false;
        $bsDetail = 'جداول سند آماده نیست';
        if (AccountingAccount::tableReady() && AccountingVoucher::tablesReady()) {
            try {
                $tb = AccountingReportService::trialBalance($atelierId);
                $tbOk = (bool) ($tb['balanced'] ?? false);
                $tbDetail = $tbOk
                    ? 'گردش و مانده متوازن است'
                    : 'گردش یا مانده تراز آزمایشی نامتوازن است';
            } catch (RuntimeException $e) {
                $tbDetail = $e->getMessage();
            }
            try {
                $bs = AccountingReportService::balanceSheet($atelierId);
                $bsOk = (bool) ($bs['equation']['balanced'] ?? false);
                $bsDetail = $bsOk
                    ? 'دارایی = بدهی + سرمایه + سود جاری'
                    : 'معادله ترازنامه برقرار نیست';
            } catch (RuntimeException $e) {
                $bsDetail = $e->getMessage();
            }
        }
        $checks[] = $this->check('تراز آزمایشی', $tbOk, $tbDetail, $tbDetail);
        $checks[] = $this->check('ترازنامه', $bsOk, $bsDetail, $bsDetail);

        $this->table(['کنترل', 'وضعیت', 'شرح'], array_map(function (array $row) {
            return [
                $row['name'],
                $row['ok'] ? 'قبول' : 'رد',
                $row['detail'],
            ];
        }, $checks));

        $this->line('');
        $this->comment('سناریوی دستی روی فروشگاه خالی (فروش ساختگی روی فروشگاه زنده نزنید):');
        $this->line('1. فروش نقد');
        $this->line('2. فروش نسیه + تسویه');
        $this->line('3. فروش چک + وصول');
        $this->line('4. فروش اقساط + یک قسط بعد');
        $this->line('5. برگشت یک خط');
        $this->line('6. فاکتور خرید ماده + تولید + فروش تولید');
        $this->line('7. هزینه جاری از تنخواه');
        $this->line('8. تطبیق روزانه');
        $this->line('بعد از همه دوباره همین دستور را بزنید؛ تراز باید سبز بماند.');

        $ok = ! in_array(false, array_column($checks, 'ok'), true);

        return $ok ? 0 : 1;
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    protected function check(string $name, bool $ok, string $pass, string $fail): array
    {
        return [
            'name' => $name,
            'ok' => $ok,
            'detail' => $ok ? $pass : $fail,
        ];
    }
}
