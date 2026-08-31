<?php

namespace App\Services;

use App\Models\AccountingAccount;
use App\Models\AccountingVoucher;
use App\Models\ShopAccount;
use Carbon\Carbon;
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
