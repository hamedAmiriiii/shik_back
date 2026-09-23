<?php

namespace App\Services;

use App\Models\AccountingVoucher;
use App\Models\ShopAccount;
use App\Models\ShopAccountBalanceAdjustment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Morilog\Jalali\Jalalian;
use RuntimeException;

/**
 * ماندهٔ عملیاتی حساب فروشگاه/تنخواه و ماندهٔ دفتر همان حساب را به رقم واقعی می‌رساند.
 * صندوق از دفتر ۱۱۱۰۱ خوانده می‌شود؛ برای آن فقط سند حسابداری زده می‌شود.
 */
class ShopAccountBalanceSetService
{
    /**
     * @param  array<int, array{shop_account_id: int, target_balance: float}>  $items
     * @return array{adjustments: int, voucher: ?AccountingVoucher, items: array<int, array<string, mixed>>}
     */
    public static function apply(int $atelierId, array $items, $date = null, ?string $description = null): array
    {
        if ($atelierId <= 0) {
            throw new RuntimeException('فروشگاه نامعتبر است.');
        }
        if (! Schema::hasTable('shop_account_balance_adjustments')) {
            throw new RuntimeException('جدول اصلاح مانده حساب وجود ندارد. migration یا فایل SQL را اجرا کنید.');
        }
        if ($items === []) {
            throw new RuntimeException('حداقل یک حساب را مشخص کنید.');
        }

        $dateString = $date
            ? AccountingLedger::eventDate($date)
            : Carbon::now('Asia/Tehran')->toDateString();
        AccountingPeriodCloseService::assertDateUnlocked($atelierId, $dateString);

        $description = $description
            ? trim($description)
            : 'اصلاح مانده حساب با موجودی واقعی';

        ChartOfAccountsSeeder::ensureForAtelier($atelierId);

        return DB::transaction(function () use ($atelierId, $items, $dateString, $description) {
            AccountingVoucherService::lockAtelier($atelierId);

            $ids = [];
            foreach ($items as $item) {
                $ids[] = (int) ($item['shop_account_id'] ?? 0);
            }
            $ids = array_values(array_unique(array_filter($ids)));
            $accounts = ShopAccount::query()
                ->forAtelier($atelierId)
                ->whereIn('id', $ids)
                ->get()
                ->keyBy('id');
            if ($accounts->count() !== count($ids)) {
                throw new RuntimeException('یکی از حساب‌ها متعلق به این فروشگاه نیست.');
            }

            $opsNow = ShopAccountBalanceService::breakdown($atelierId, $ids);
            $lines = [];
            $created = [];
            $report = [];
            $firstId = 0;

            foreach ($items as $item) {
                $shopId = (int) ($item['shop_account_id'] ?? 0);
                $target = round((float) ($item['target_balance'] ?? 0), 2);
                /** @var ShopAccount $shop */
                $shop = $accounts->get($shopId);
                $ops = round((float) ($opsNow[$shopId]['balance'] ?? 0), 2);
                $ledgerId = AccountingLedger::shopCashAccountId($atelierId, $shopId);
                $ledger = self::ledgerNetDebitById($atelierId, $ledgerId);

                $opsDelta = $shop->isTill() ? 0.0 : round($target - $ops, 2);
                $ledgerDelta = round($target - $ledger, 2);

                if (abs($opsDelta) < 0.01 && abs($ledgerDelta) < 0.01) {
                    $report[] = [
                        'shop_account_id' => $shopId,
                        'name' => $shop->name,
                        'target' => $target,
                        'operational_delta' => 0.0,
                        'ledger_delta' => 0.0,
                        'skipped' => true,
                    ];
                    continue;
                }

                $row = ShopAccountBalanceAdjustment::create([
                    'atelier_id' => $atelierId,
                    'shop_account_id' => $shopId,
                    'amount' => $opsDelta,
                    'date' => $dateString,
                    'description' => $description,
                ]);
                if ($firstId === 0) {
                    $firstId = (int) $row->id;
                }
                $created[] = $row;

                if ($ledgerDelta > 0.009) {
                    AccountingLedger::push($lines, $ledgerId, $ledgerDelta, 0, $shop->name);
                } elseif ($ledgerDelta < -0.009) {
                    AccountingLedger::push($lines, $ledgerId, 0, abs($ledgerDelta), $shop->name);
                }

                $report[] = [
                    'shop_account_id' => $shopId,
                    'name' => $shop->name,
                    'target' => $target,
                    'operational_delta' => $opsDelta,
                    'ledger_delta' => $ledgerDelta,
                    'skipped' => false,
                ];
            }

            if ($created === []) {
                throw new RuntimeException('ماندهٔ این حساب‌ها از قبل با رقم واردشده یکی است.');
            }

            $voucher = null;
            if (count($lines) >= 1) {
                $net = 0.0;
                foreach ($lines as $line) {
                    $net += (float) $line['debit'] - (float) $line['credit'];
                }
                $net = round($net, 2);
                $equityId = AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_EQUITY);
                if ($net > 0.009) {
                    AccountingLedger::push($lines, $equityId, 0, $net, 'سرمایه — اصلاح مانده واقعی');
                } elseif ($net < -0.009) {
                    AccountingLedger::push($lines, $equityId, abs($net), 0, 'سرمایه — اصلاح مانده واقعی');
                }
                if (count($lines) >= 2) {
                    $voucher = AccountingVoucherService::post(
                        $atelierId,
                        $dateString,
                        $description,
                        AccountingVoucher::SOURCE_BALANCE_ADJUST,
                        $firstId,
                        $lines
                    );
                }
            }

            return [
                'adjustments' => count($created),
                'voucher' => $voucher,
                'items' => $report,
            ];
        }, 5);
    }

    public static function parseDate(?string $date): string
    {
        if (! $date) {
            return Carbon::now('Asia/Tehran')->toDateString();
        }
        try {
            return Jalalian::fromFormat('Y-m-d', $date)->toCarbon()->toDateString();
        } catch (\Throwable $e) {
            return Carbon::parse($date, 'Asia/Tehran')->toDateString();
        }
    }

    protected static function ledgerNetDebitById(int $atelierId, int $accountId): float
    {
        if ($accountId <= 0 || ! AccountingLedger::ready()) {
            return 0.0;
        }

        $row = DB::table('accounting_lines as l')
            ->join('accounting_vouchers as v', 'v.id', '=', 'l.voucher_id')
            ->where('v.atelier_id', $atelierId)
            ->whereIn('v.status', ['posted', 'reversed'])
            ->where('l.account_id', $accountId)
            ->selectRaw('COALESCE(SUM(l.debit), 0) as d, COALESCE(SUM(l.credit), 0) as c')
            ->first();

        return round((float) ($row->d ?? 0) - (float) ($row->c ?? 0), 2);
    }
}
