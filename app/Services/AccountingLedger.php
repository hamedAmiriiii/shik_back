<?php

namespace App\Services;

use App\Models\AccountingAccount;
use App\Models\AccountingVoucher;
use App\Models\ShopAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class AccountingLedger
{
    public static function ready(): bool
    {
        return AccountingAccount::tableReady() && AccountingVoucher::tablesReady();
    }

    public static function accountId(int $atelierId, string $code): int
    {
        ChartOfAccountsSeeder::ensureForAtelier($atelierId);
        $id = AccountingAccount::query()
            ->forAtelier($atelierId)
            ->where('code', $code)
            ->value('id');
        if (! $id) {
            throw new RuntimeException('حساب '.$code.' در کدینگ این فروشگاه یافت نشد.');
        }

        return (int) $id;
    }

    public static function shopCashAccountId(int $atelierId, int $shopAccountId): int
    {
        ChartOfAccountsSeeder::ensureForAtelier($atelierId);
        $shop = ShopAccount::query()->find($shopAccountId);
        if ($shop && (int) $shop->atelier_id === $atelierId && $shop->isTill()) {
            return self::accountId($atelierId, ChartOfAccountsSeeder::CODE_TILL);
        }
        $id = self::linkedShopAccountId($atelierId, $shopAccountId);
        if ($id) {
            return $id;
        }

        $shop = ShopAccount::query()->find($shopAccountId);
        if ($shop && (int) $shop->atelier_id === $atelierId) {
            ChartOfAccountsSeeder::syncShopAccount($shop);
            $id = self::linkedShopAccountId($atelierId, $shopAccountId);
        }
        if (! $id) {
            throw new RuntimeException('تفصیلی حساب فروشگاه یافت نشد.');
        }

        return $id;
    }

    protected static function linkedShopAccountId(int $atelierId, int $shopAccountId): ?int
    {
        $id = AccountingAccount::query()
            ->forAtelier($atelierId)
            ->where('linked_type', AccountingAccount::LINK_SHOP_ACCOUNT)
            ->where('linked_id', $shopAccountId)
            ->value('id');

        return $id ? (int) $id : null;
    }

    /** مانده بدهکار خالص یک حساب تفصیلی (بدهکار − بستانکار). */
    public static function netDebit(int $atelierId, string $code): float
    {
        if ($atelierId <= 0 || ! self::ready() || ! Schema::hasTable('accounting_lines')) {
            return 0.0;
        }

        $accountId = AccountingAccount::query()
            ->forAtelier($atelierId)
            ->where('code', $code)
            ->value('id');
        if (! $accountId) {
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

    public static function eventDate($value): string
    {
        if (! $value) {
            return Carbon::now('Asia/Tehran')->toDateString();
        }
        if ($value instanceof Carbon) {
            return $value->timezone('Asia/Tehran')->toDateString();
        }

        return Carbon::parse((string) $value)->timezone('Asia/Tehran')->toDateString();
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function push(array &$lines, int $accountId, float $debit, float $credit, string $description = ''): void
    {
        $debit = round($debit, 2);
        $credit = round($credit, 2);
        if ($debit < 0.01 && $credit < 0.01) {
            return;
        }
        $lines[] = [
            'account_id' => $accountId,
            'debit' => $debit,
            'credit' => $credit,
            'description' => $description,
        ];
    }
}
