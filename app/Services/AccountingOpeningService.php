<?php

namespace App\Services;

use App\Models\AccountingVoucher;
use App\Models\ShopAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * سند افتتاحیهٔ نقد: اختلاف مانده عملیاتی حساب‌های فروشگاه با دفتر را به سرمایه می‌بندد.
 * سیاست «از امروز به بعد» — تاریخچه فروش/خرید بازسازی نمی‌شود.
 */
class AccountingOpeningService
{
    public const SOURCE_ID = 1;

    /**
     * @return array{voucher: ?AccountingVoucher, already_posted: bool, skipped: bool}
     */
    public static function post(int $atelierId, $date = null): array
    {
        if ($atelierId <= 0) {
            throw new RuntimeException('فروشگاه نامعتبر است.');
        }
        if (! AccountingLedger::ready()) {
            throw new RuntimeException('جدول سند حسابداری وجود ندارد. migration یا فایل SQL را اجرا کنید.');
        }

        ChartOfAccountsSeeder::ensureForAtelier($atelierId);

        $existing = AccountingVoucherService::findPosted(
            $atelierId,
            AccountingVoucher::SOURCE_OPENING,
            self::SOURCE_ID
        );
        if ($existing) {
            return [
                'voucher' => $existing->load(['lines.account']),
                'already_posted' => true,
                'skipped' => false,
            ];
        }

        $shops = ShopAccount::query()->forAtelier($atelierId)->get();
        $shopIds = $shops->pluck('id')->map(fn ($id) => (int) $id)->all();
        $operational = $shopIds !== []
            ? ShopAccountBalanceService::balances($atelierId, $shopIds)
            : [];

        $lines = [];
        foreach ($shops as $shop) {
            $shopId = (int) $shop->id;
            $ops = round((float) ($operational[$shopId] ?? 0), 2);
            $cashId = AccountingLedger::shopCashAccountId($atelierId, $shopId);
            $ledger = self::ledgerSignedBalance($atelierId, $cashId);
            $gap = round($ops - $ledger, 2);
            if ($gap > 0.009) {
                AccountingLedger::push($lines, $cashId, $gap, 0, (string) $shop->name);
            } elseif ($gap < -0.009) {
                AccountingLedger::push($lines, $cashId, 0, abs($gap), (string) $shop->name);
            }
        }

        if ($lines === []) {
            return [
                'voucher' => null,
                'already_posted' => false,
                'skipped' => true,
            ];
        }

        $debit = 0.0;
        $credit = 0.0;
        foreach ($lines as $line) {
            $debit += (float) $line['debit'];
            $credit += (float) $line['credit'];
        }
        $net = round($debit - $credit, 2);
        $equityId = AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_EQUITY);
        if ($net > 0.009) {
            AccountingLedger::push($lines, $equityId, 0, $net, 'سرمایه اول دوره');
        } elseif ($net < -0.009) {
            AccountingLedger::push($lines, $equityId, abs($net), 0, 'سرمایه اول دوره');
        }

        $voucherDate = $date ?: Carbon::now('Asia/Tehran')->toDateString();
        $voucher = AccountingVoucherService::post(
            $atelierId,
            $voucherDate,
            'افتتاحیه — مانده نقد عملیاتی',
            AccountingVoucher::SOURCE_OPENING,
            self::SOURCE_ID,
            $lines
        );

        return [
            'voucher' => $voucher,
            'already_posted' => false,
            'skipped' => false,
        ];
    }

    protected static function ledgerSignedBalance(int $atelierId, int $accountId): float
    {
        $row = DB::table('accounting_lines')
            ->join('accounting_vouchers', 'accounting_vouchers.id', '=', 'accounting_lines.voucher_id')
            ->where('accounting_vouchers.atelier_id', $atelierId)
            ->whereIn('accounting_vouchers.status', [
                AccountingVoucher::STATUS_POSTED,
                AccountingVoucher::STATUS_REVERSED,
            ])
            ->where('accounting_lines.account_id', $accountId)
            ->selectRaw('COALESCE(SUM(accounting_lines.debit), 0) as debit_sum, COALESCE(SUM(accounting_lines.credit), 0) as credit_sum')
            ->first();

        return round((float) ($row->debit_sum ?? 0) - (float) ($row->credit_sum ?? 0), 2);
    }
}
