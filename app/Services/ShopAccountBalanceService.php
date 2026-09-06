<?php

namespace App\Services;

use App\Models\AccountingAccount;
use App\Models\DailyShopReconciliationAccountDeposit;
use App\Models\DocumentPayment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\ManualTrade;
use App\Models\ShopAccount;
use App\Models\ShopAccountTransfer;
use App\Services\ChartOfAccountsSeeder;
use App\Services\DocumentPaymentService;
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
                'manual_purchases' => 0.0,
                'manual_sales' => 0.0,
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

        foreach (self::tradeTotals($atelierId, $accountIds, ManualTrade::TYPE_PURCHASE) as $id => $total) {
            if (isset($result[$id])) {
                $result[$id]['manual_purchases'] = $total;
            }
        }

        foreach (self::tradeTotals($atelierId, $accountIds, ManualTrade::TYPE_SALE) as $id => $total) {
            if (isset($result[$id])) {
                $result[$id]['manual_sales'] = $total;
            }
        }

        foreach ($result as $id => $row) {
            $result[$id]['balance'] = round(
                $row['deposits'] + $row['transfers_in'] + $row['manual_sales']
                    - $row['transfers_out'] - $row['expenses'] - $row['invoices'] - $row['manual_purchases'],
                2
            );
        }

        self::overlayTillLedgerBalances($atelierId, $result);

        return $result;
    }

    /**
     * موجودی صندوق نقد = مانده دفتر ۱۱۱۰۱ (فروش نقد/کارت − واریز تطبیق − پرداخت از صندوق).
     *
     * @param  array<int, array<string, float>>  $result
     */
    protected static function overlayTillLedgerBalances(int $atelierId, array &$result): void
    {
        if ($result === [] || ! Schema::hasTable('accounting_lines') || ! Schema::hasTable('accounting_vouchers')) {
            return;
        }

        $tillIds = ShopAccount::query()
            ->forAtelier($atelierId)
            ->where(function ($q) {
                $q->where('type', ShopAccount::TYPE_TILL)
                    ->orWhere('legacy_slot', ShopAccount::LEGACY_TILL);
            })
            ->pluck('id')
            ->all();
        $tillIds = array_values(array_intersect($tillIds, array_keys($result)));
        if ($tillIds === []) {
            return;
        }

        $tillAccountId = AccountingAccount::query()
            ->forAtelier($atelierId)
            ->where('code', ChartOfAccountsSeeder::CODE_TILL)
            ->value('id');
        if (! $tillAccountId) {
            return;
        }

        $row = DB::table('accounting_lines as l')
            ->join('accounting_vouchers as v', 'v.id', '=', 'l.voucher_id')
            ->where('v.atelier_id', $atelierId)
            ->whereIn('v.status', ['posted', 'reversed'])
            ->where('l.account_id', $tillAccountId)
            ->selectRaw('COALESCE(SUM(l.debit), 0) as d, COALESCE(SUM(l.credit), 0) as c')
            ->first();
        $balance = round((float) ($row->d ?? 0) - (float) ($row->c ?? 0), 2);
        foreach ($tillIds as $id) {
            $result[$id]['balance'] = $balance;
        }
    }

    /**
     * پرداخت نقد از صندوق در یک روز (برای تطبیق: ماندهٔ قابل واریز به بانک).
     */
    public static function tillPaidOnDate(int $atelierId, string $date): float
    {
        $tillIds = ShopAccount::query()
            ->forAtelier($atelierId)
            ->where(function ($q) {
                $q->where('type', ShopAccount::TYPE_TILL)
                    ->orWhere('legacy_slot', ShopAccount::LEGACY_TILL);
            })
            ->pluck('id')
            ->all();
        if ($tillIds === []) {
            return 0.0;
        }

        $total = 0.0;
        foreach (['expenses' => Expense::class, 'invoices' => Invoice::class] as $table => $model) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'shop_account_id')) {
                continue;
            }
            $fk = $table === 'expenses' ? 'expense_id' : 'invoice_id';
            $legacy = $model::query()
                ->where('atelier_id', $atelierId)
                ->whereIn('shop_account_id', $tillIds)
                ->whereDate('date', $date)
                ->when(
                    Schema::hasColumn($table, 'payment_status'),
                    fn ($q) => $q->where('payment_status', 'paid')
                );
            if (Schema::hasTable('document_payments')) {
                $legacy->whereNotIn('id', function ($q) use ($fk) {
                    $q->select($fk)->from('document_payments')->whereNotNull($fk);
                });
            }
            $total += (float) $legacy->sum('amount');
            if (Schema::hasTable('document_payments')) {
                $total += (float) DocumentPayment::query()
                    ->where('atelier_id', $atelierId)
                    ->where('settled', true)
                    ->whereIn('shop_account_id', $tillIds)
                    ->whereNotNull($fk)
                    ->whereDate('created_at', $date)
                    ->sum('amount');
            }
        }

        return round($total, 2);
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
     * موجودی قابل برداشت. اگر سند پرداخت‌شده همین حساب را ignore کنیم، مبلغش به موجودی برمی‌گردد.
     */
    public static function availableBalance(ShopAccount $account, $ignorePaidDocument = null): float
    {
        $available = self::balanceFor($account);
        if (! $ignorePaidDocument) {
            return $available;
        }

        $addBack = DocumentPaymentService::settledAmountOnAccount($ignorePaidDocument, (int) $account->id);

        return round($available + $addBack, 2);
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
        $fk = $table === 'expenses' ? 'expense_id' : 'invoice_id';

        $legacyQuery = $model::query()
            ->where('atelier_id', $atelierId)
            ->whereIn('shop_account_id', $accountIds)
            ->when(
                Schema::hasColumn($table, 'payment_status'),
                function ($q) {
                    $q->where('payment_status', 'paid');
                }
            );

        if (Schema::hasTable('document_payments')) {
            $legacyQuery->whereNotIn('id', function ($q) use ($fk) {
                $q->select($fk)->from('document_payments')->whereNotNull($fk);
            });
        }

        $totals = $legacyQuery
            ->selectRaw('shop_account_id, SUM(amount) as total')
            ->groupBy('shop_account_id')
            ->pluck('total', 'shop_account_id')
            ->mapWithKeys(fn ($v, $k) => [(int) $k => (float) $v])
            ->all();

        if (Schema::hasTable('document_payments')) {
            $splitTotals = DocumentPayment::query()
                ->where('atelier_id', $atelierId)
                ->where('settled', true)
                ->whereIn('shop_account_id', $accountIds)
                ->whereNotNull($fk)
                ->selectRaw('shop_account_id, SUM(amount) as total')
                ->groupBy('shop_account_id')
                ->pluck('total', 'shop_account_id')
                ->mapWithKeys(fn ($v, $k) => [(int) $k => (float) $v])
                ->all();
            foreach ($splitTotals as $id => $total) {
                $totals[$id] = round(($totals[$id] ?? 0) + $total, 2);
            }
        }

        return $totals;
    }

    /**
     * @param  array<int>  $accountIds
     * @return array<int, float>
     */
    protected static function tradeTotals(int $atelierId, array $accountIds, string $type): array
    {
        if (! ManualTrade::tableReady() || ! Schema::hasColumn('manual_trades', 'shop_account_id')) {
            return [];
        }

        return ManualTrade::query()
            ->where('atelier_id', $atelierId)
            ->where('type', $type)
            ->whereIn('shop_account_id', $accountIds)
            ->selectRaw('shop_account_id, SUM(amount) as total')
            ->groupBy('shop_account_id')
            ->pluck('total', 'shop_account_id')
            ->mapWithKeys(fn ($v, $k) => [(int) $k => (float) $v])
            ->all();
    }
}
