<?php

namespace App\Services;

use App\Models\DailyShopReconciliationAccountDeposit;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\ShopAccount;
use App\Models\ShopAccountTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * موجودی حساب‌های فروشگاه و تنخواه.
 *
 * حساب فروشگاه: واریزهای تطبیق روزانه − شارژ تنخواه − هزینه/فاکتور پرداخت‌شده از آن
 * تنخواه: شارژ دریافتی − هزینه/فاکتور پرداخت‌شده از آن
 */
class ShopAccountBalanceService
{
    /**
     * تفکیک کامل موجودی برای مجموعه‌ای از حساب‌ها.
     *
     * @param  array<int>  $accountIds
     * @return array<int, array{deposits: float, transfers_in: float, transfers_out: float, expenses: float, invoices: float, balance: float}>
     */
    public static function breakdown(int $atelierId, array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        $result = [];
        foreach ($accountIds as $id) {
            $result[(int) $id] = [
                'deposits' => 0.0,
                'transfers_in' => 0.0,
                'transfers_out' => 0.0,
                'expenses' => 0.0,
                'invoices' => 0.0,
                'balance' => 0.0,
            ];
        }

        foreach (self::depositTotals($atelierId, $accountIds) as $id => $total) {
            if (isset($result[$id])) {
                $result[$id]['deposits'] = $total;
            }
        }

        [$transfersIn, $transfersOut] = self::transferTotals($atelierId, $accountIds);
        foreach ($transfersIn as $id => $total) {
            if (isset($result[$id])) {
                $result[$id]['transfers_in'] = $total;
            }
        }
        foreach ($transfersOut as $id => $total) {
            if (isset($result[$id])) {
                $result[$id]['transfers_out'] = $total;
            }
        }

        foreach (self::spendingTotals('expenses', $atelierId, $accountIds) as $id => $total) {
            if (isset($result[$id])) {
                $result[$id]['expenses'] = $total;
            }
        }

        foreach (self::spendingTotals('invoices', $atelierId, $accountIds) as $id => $total) {
            if (isset($result[$id])) {
                $result[$id]['invoices'] = $total;
            }
        }

        foreach ($result as $id => $row) {
            $result[$id]['balance'] = round(
                $row['deposits'] + $row['transfers_in']
                    - $row['transfers_out'] - $row['expenses'] - $row['invoices'],
                2
            );
        }

        return $result;
    }

    /**
     * فقط موجودی نهایی هر حساب.
     *
     * @param  array<int>  $accountIds
     * @return array<int, float>
     */
    public static function balances(int $atelierId, array $accountIds): array
    {
        return array_map(
            fn (array $row) => $row['balance'],
            self::breakdown($atelierId, $accountIds)
        );
    }

    public static function balanceFor(ShopAccount $account): float
    {
        $balances = self::balances((int) $account->atelier_id, [(int) $account->id]);

        return (float) ($balances[(int) $account->id] ?? 0);
    }

    /**
     * واریزهای تطبیق روزانه (با تکمیل از ستون‌های قدیمی deposit_account_1/2).
     *
     * @param  array<int>  $accountIds
     * @return array<int, float>
     */
    protected static function depositTotals(int $atelierId, array $accountIds): array
    {
        $totals = [];

        if (Schema::hasTable('daily_shop_reconciliation_account_deposits')) {
            $totals = DailyShopReconciliationAccountDeposit::query()
                ->whereIn('shop_account_id', $accountIds)
                ->whereHas('shopAccount', fn ($q) => $q->where('atelier_id', $atelierId))
                ->selectRaw('shop_account_id, SUM(amount) as total')
                ->groupBy('shop_account_id')
                ->pluck('total', 'shop_account_id')
                ->mapWithKeys(fn ($v, $k) => [(int) $k => (float) $v])
                ->all();
        }

        if (! Schema::hasTable('daily_shop_reconciliations') || ! Schema::hasTable('shop_accounts')) {
            return $totals;
        }

        // روزهایی که هنوز ردیف جدید ندارند از ستون‌های قدیمی خوانده می‌شوند
        $legacyAccounts = ShopAccount::query()
            ->forAtelier($atelierId)
            ->whereIn('id', $accountIds)
            ->whereIn('legacy_slot', [ShopAccount::LEGACY_ACCOUNT_1, ShopAccount::LEGACY_ACCOUNT_2])
            ->get();

        foreach ($legacyAccounts as $account) {
            $column = $account->legacy_slot === ShopAccount::LEGACY_ACCOUNT_1
                ? 'deposit_account_1'
                : 'deposit_account_2';

            $legacySum = (float) DB::table('daily_shop_reconciliations as r')
                ->where('r.atelier_id', $atelierId)
                ->whereNotExists(function ($sub) use ($account) {
                    $sub->select(DB::raw(1))
                        ->from('daily_shop_reconciliation_account_deposits as d')
                        ->whereColumn('d.reconciliation_id', 'r.id')
                        ->where('d.shop_account_id', $account->id)
                        ->where('d.amount', '>', 0);
                })
                ->sum("r.{$column}");

            if ($legacySum > 0) {
                $totals[(int) $account->id] = round(($totals[(int) $account->id] ?? 0) + $legacySum, 2);
            }
        }

        return $totals;
    }

    /**
     * @param  array<int>  $accountIds
     * @return array{0: array<int, float>, 1: array<int, float>}
     */
    protected static function transferTotals(int $atelierId, array $accountIds): array
    {
        if (! Schema::hasTable('shop_account_transfers')) {
            return [[], []];
        }

        $in = ShopAccountTransfer::query()
            ->forAtelier($atelierId)
            ->whereIn('to_shop_account_id', $accountIds)
            ->selectRaw('to_shop_account_id as account_id, SUM(amount) as total')
            ->groupBy('to_shop_account_id')
            ->pluck('total', 'account_id')
            ->mapWithKeys(fn ($v, $k) => [(int) $k => (float) $v])
            ->all();

        $out = ShopAccountTransfer::query()
            ->forAtelier($atelierId)
            ->whereIn('from_shop_account_id', $accountIds)
            ->selectRaw('from_shop_account_id as account_id, SUM(amount) as total')
            ->groupBy('from_shop_account_id')
            ->pluck('total', 'account_id')
            ->mapWithKeys(fn ($v, $k) => [(int) $k => (float) $v])
            ->all();

        return [$in, $out];
    }

    /**
     * @param  array<int>  $accountIds
     * @return array<int, float>
     */
    protected static function spendingTotals(string $table, int $atelierId, array $accountIds): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'shop_account_id')) {
            return [];
        }

        $model = $table === 'expenses' ? Expense::class : Invoice::class;

        return $model::query()
            ->where('atelier_id', $atelierId)
            ->whereIn('shop_account_id', $accountIds)
            ->selectRaw('shop_account_id, SUM(amount) as total')
            ->groupBy('shop_account_id')
            ->pluck('total', 'shop_account_id')
            ->mapWithKeys(fn ($v, $k) => [(int) $k => (float) $v])
            ->all();
    }
}
