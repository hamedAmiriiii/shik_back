<?php

namespace App\Services;

use App\Models\AccountingAccount;
use App\Models\AccountingVoucher;
use App\Models\ShopAccount;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Morilog\Jalali\Jalalian;
use RuntimeException;

class AccountingReportService
{
    public const PNL_NOTE = 'مبنای دفتر تعهدی است (اقساط و چک روز فروش؛ اعتبار فقط در ۶۱۳). اختلاف جزئی با گزارش POS ممکن است به‌خاطر تخفیف خط یا زمان‌بندی باشد.';

    /**
     * @return array<string, mixed>
     */
    public static function trialBalance(int $atelierId, ?string $from = null, ?string $to = null, bool $includeZero = false): array
    {
        $fromG = self::parseDate($from);
        $toG = self::parseDate($to);
        self::assertRange($fromG, $toG);

        $turnover = self::turnoverByAccount($atelierId, $fromG, $toG);
        $accounts = self::reportAccounts($atelierId);
        $rows = [];
        $sumDebitTurnover = 0.0;
        $sumCreditTurnover = 0.0;
        $sumDebitBalance = 0.0;
        $sumCreditBalance = 0.0;

        foreach ($accounts as $account) {
            $pair = $turnover[(int) $account->id] ?? ['debit' => 0.0, 'credit' => 0.0];
            if (! $includeZero && $pair['debit'] < 0.01 && $pair['credit'] < 0.01) {
                continue;
            }
            $split = self::splitBalance($account->nature, $pair['debit'], $pair['credit']);
            $rows[] = self::accountRow($account, $pair, $split);
            $sumDebitTurnover += $pair['debit'];
            $sumCreditTurnover += $pair['credit'];
            $sumDebitBalance += $split['debit_balance'];
            $sumCreditBalance += $split['credit_balance'];
        }

        $sumDebitTurnover = round($sumDebitTurnover, 2);
        $sumCreditTurnover = round($sumCreditTurnover, 2);
        $sumDebitBalance = round($sumDebitBalance, 2);
        $sumCreditBalance = round($sumCreditBalance, 2);

        return [
            'from' => self::toJalali($fromG),
            'to' => self::toJalali($toG),
            'rows' => $rows,
            'totals' => [
                'debit_turnover' => $sumDebitTurnover,
                'credit_turnover' => $sumCreditTurnover,
                'debit_balance' => $sumDebitBalance,
                'credit_balance' => $sumCreditBalance,
            ],
            'balanced' => abs($sumDebitTurnover - $sumCreditTurnover) < 0.015
                && abs($sumDebitBalance - $sumCreditBalance) < 0.015,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function ledger(int $atelierId, int $accountId, ?string $from = null, ?string $to = null): array
    {
        $account = AccountingAccount::query()->forAtelier($atelierId)->where('id', $accountId)->first();
        if (! $account) {
            throw new RuntimeException('حساب یافت نشد.');
        }

        $fromG = self::parseDate($from);
        $toG = self::parseDate($to);
        self::assertRange($fromG, $toG);

        $opening = ['debit' => 0.0, 'credit' => 0.0];
        if ($fromG) {
            $opening = self::turnoverByAccount($atelierId, null, self::dayBefore($fromG), $accountId)[$accountId]
                ?? ['debit' => 0.0, 'credit' => 0.0];
        }
        $openingSplit = self::splitBalance($account->nature, $opening['debit'], $opening['credit']);
        $running = self::signedBalance($account->nature, $opening['debit'], $opening['credit']);

        $lines = self::postedLinesQuery($atelierId, $fromG, $toG)
            ->where('accounting_lines.account_id', $accountId)
            ->orderBy('accounting_vouchers.date')
            ->orderBy('accounting_vouchers.number')
            ->orderBy('accounting_lines.sort_order')
            ->orderBy('accounting_lines.id')
            ->get();

        $rows = [];
        $periodDebit = 0.0;
        $periodCredit = 0.0;
        foreach ($lines as $line) {
            $debit = round((float) $line->debit, 2);
            $credit = round((float) $line->credit, 2);
            $periodDebit += $debit;
            $periodCredit += $credit;
            $running += self::signedDelta($account->nature, $debit, $credit);
            $runSplit = self::splitFromSigned($account->nature, $running);
            $rows[] = [
                'line_id' => (int) $line->id,
                'voucher_id' => (int) $line->voucher_id,
                'number' => (int) $line->voucher_number,
                'date' => self::toJalali($line->voucher_date),
                'description' => $line->voucher_description,
                'line_description' => $line->description,
                'source_type' => $line->source_type,
                'is_reversal' => $line->reverses_voucher_id !== null,
                'debit' => $debit,
                'credit' => $credit,
                'running' => round($running, 2),
                'running_debit' => $runSplit['debit_balance'],
                'running_credit' => $runSplit['credit_balance'],
            ];
        }

        $closingDebit = $opening['debit'] + $periodDebit;
        $closingCredit = $opening['credit'] + $periodCredit;
        $closingSplit = self::splitBalance($account->nature, $closingDebit, $closingCredit);

        return [
            'account' => self::accountMeta($account),
            'from' => self::toJalali($fromG),
            'to' => self::toJalali($toG),
            'opening' => [
                'debit' => round($opening['debit'], 2),
                'credit' => round($opening['credit'], 2),
                'debit_balance' => $openingSplit['debit_balance'],
                'credit_balance' => $openingSplit['credit_balance'],
            ],
            'rows' => $rows,
            'period' => [
                'debit' => round($periodDebit, 2),
                'credit' => round($periodCredit, 2),
            ],
            'closing' => [
                'debit' => round($closingDebit, 2),
                'credit' => round($closingCredit, 2),
                'debit_balance' => $closingSplit['debit_balance'],
                'credit_balance' => $closingSplit['credit_balance'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function profitLoss(int $atelierId, ?string $from = null, ?string $to = null): array
    {
        $fromG = self::parseDate($from);
        $toG = self::parseDate($to);
        self::assertRange($fromG, $toG);

        $byCode = self::netByCode($atelierId, $fromG, $toG);
        $sales = self::creditNet($byCode, ChartOfAccountsSeeder::CODE_REVENUE);
        $discounts = self::debitNet($byCode, ChartOfAccountsSeeder::CODE_DISCOUNT);
        $cogs = self::debitNet($byCode, ChartOfAccountsSeeder::CODE_COGS);
        $opex = self::debitNet($byCode, ChartOfAccountsSeeder::CODE_EXPENSE);
        $payroll = self::debitNet($byCode, ChartOfAccountsSeeder::CODE_PAYROLL);
        $loyalty = self::debitNet($byCode, ChartOfAccountsSeeder::CODE_LOYALTY);
        $otherIncome = self::creditNet($byCode, ChartOfAccountsSeeder::CODE_OTHER_INCOME);
        $gross = round($sales - $discounts - $cogs, 2);
        $net = round($gross - $opex - $payroll - $loyalty + $otherIncome, 2);

        return [
            'from' => self::toJalali($fromG),
            'to' => self::toJalali($toG),
            'sales' => $sales,
            'discounts' => $discounts,
            'cogs' => $cogs,
            'gross_profit' => $gross,
            'operating_expense' => $opex,
            'payroll' => $payroll,
            'loyalty' => $loyalty,
            'other_income' => $otherIncome,
            'net_profit' => $net,
            'note' => self::PNL_NOTE,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function balanceSheet(int $atelierId, ?string $asOf = null): array
    {
        $asOfG = self::parseDate($asOf) ?: Carbon::now('Asia/Tehran')->toDateString();
        $turnover = self::turnoverByAccount($atelierId, null, $asOfG);
        $accounts = self::reportAccounts($atelierId);

        $assets = [];
        $liabilities = [];
        $equity = [];
        $assetTotal = 0.0;
        $liabilityTotal = 0.0;
        $equityTotal = 0.0;

        foreach ($accounts as $account) {
            $pair = $turnover[(int) $account->id] ?? ['debit' => 0.0, 'credit' => 0.0];
            $split = self::splitBalance($account->nature, $pair['debit'], $pair['credit']);
            if ($split['debit_balance'] < 0.01 && $split['credit_balance'] < 0.01) {
                continue;
            }
            $row = self::accountRow($account, $pair, $split);
            $signed = self::signedBalance($account->nature, $pair['debit'], $pair['credit']);
            if ($account->kind === AccountingAccount::KIND_ASSET) {
                $assets[] = $row;
                $assetTotal += $signed;
            } elseif ($account->kind === AccountingAccount::KIND_LIABILITY) {
                $liabilities[] = $row;
                $liabilityTotal += $signed;
            } elseif ($account->kind === AccountingAccount::KIND_EQUITY) {
                $equity[] = $row;
                $equityTotal += $signed;
            }
        }

        $pnl = self::profitLoss($atelierId, null, $asOfG);
        $currentProfit = (float) $pnl['net_profit'];
        $assetTotal = round($assetTotal, 2);
        $liabilityTotal = round($liabilityTotal, 2);
        $equityTotal = round($equityTotal, 2);
        $right = round($liabilityTotal + $equityTotal + $currentProfit, 2);

        return [
            'as_of' => self::toJalali($asOfG),
            'assets' => [
                'rows' => $assets,
                'total' => $assetTotal,
            ],
            'liabilities' => [
                'rows' => $liabilities,
                'total' => $liabilityTotal,
            ],
            'equity' => [
                'rows' => $equity,
                'total' => $equityTotal,
            ],
            'current_profit' => $currentProfit,
            'current_profit_label' => 'سود جاری',
            'equation' => [
                'assets' => $assetTotal,
                'liabilities_equity_profit' => $right,
                'balanced' => abs($assetTotal - $right) < 0.015,
            ],
            'cash_compare' => self::cashCompare($atelierId, $turnover),
            'note' => self::PNL_NOTE,
        ];
    }

    public static function parseDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m) && (int) $m[1] > 1600) {
            return Carbon::parse($value, 'Asia/Tehran')->toDateString();
        }
        try {
            return Jalalian::fromFormat('Y-m-d', $value)->toCarbon()->toDateString();
        } catch (\Throwable $e) {
            try {
                return Carbon::parse($value, 'Asia/Tehran')->toDateString();
            } catch (\Throwable $e2) {
                throw new RuntimeException('تاریخ نامعتبر است.');
            }
        }
    }

    protected static function assertRange(?string $from, ?string $to): void
    {
        if ($from && $to && $from > $to) {
            throw new RuntimeException('بازهٔ تاریخ نامعتبر است.');
        }
    }

    protected static function toJalali(?string $gregorian): ?string
    {
        if (! $gregorian) {
            return null;
        }

        return Jalalian::fromCarbon(Carbon::parse($gregorian))->format('Y-m-d');
    }

    protected static function dayBefore(string $gregorian): string
    {
        return Carbon::parse($gregorian)->subDay()->toDateString();
    }

    /**
     * معین بدون تفصیلی + همهٔ تفصیلی‌ها، به ترتیب درخت.
     *
     * @return Collection<int, AccountingAccount>
     */
    protected static function reportAccounts(int $atelierId): Collection
    {
        $all = AccountingAccount::query()->forAtelier($atelierId)->orderBy('code')->get();
        $childIds = $all->whereNotNull('parent_id')->pluck('parent_id')->unique()->map(fn ($id) => (int) $id)->all();
        $ordered = collect();
        $byParent = $all->groupBy(fn (AccountingAccount $a) => (string) ($a->parent_id ?? '0'));
        $walk = function ($parentKey) use (&$walk, &$ordered, $byParent, $childIds) {
            foreach ($byParent->get($parentKey, collect())->sortBy('code') as $node) {
                $isLeafMoein = $node->level === AccountingAccount::LEVEL_MOEIN
                    && ! in_array($node->id, $childIds, true);
                if ($node->level === AccountingAccount::LEVEL_TAFSILI || $isLeafMoein) {
                    $ordered->push($node);
                }
                $walk((string) $node->id);
            }
        };
        $walk('0');

        return $ordered;
    }

    /**
     * @return array<int, array{debit: float, credit: float}>
     */
    protected static function turnoverByAccount(int $atelierId, ?string $from, ?string $to, ?int $onlyAccountId = null): array
    {
        $query = self::postedJoin($atelierId, $from, $to)
            ->select([
                'accounting_lines.account_id',
                DB::raw('SUM(accounting_lines.debit) as debit_sum'),
                DB::raw('SUM(accounting_lines.credit) as credit_sum'),
            ])
            ->groupBy('accounting_lines.account_id');
        if ($onlyAccountId) {
            $query->where('accounting_lines.account_id', $onlyAccountId);
        }

        $out = [];
        foreach ($query->get() as $row) {
            $out[(int) $row->account_id] = [
                'debit' => round((float) $row->debit_sum, 2),
                'credit' => round((float) $row->credit_sum, 2),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, array{debit: float, credit: float}>
     */
    protected static function netByCode(int $atelierId, ?string $from, ?string $to): array
    {
        $rows = self::postedJoin($atelierId, $from, $to)
            ->join('accounting_accounts', 'accounting_accounts.id', '=', 'accounting_lines.account_id')
            ->select([
                'accounting_accounts.code',
                DB::raw('SUM(accounting_lines.debit) as debit_sum'),
                DB::raw('SUM(accounting_lines.credit) as credit_sum'),
            ])
            ->groupBy('accounting_accounts.code')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->code] = [
                'debit' => round((float) $row->debit_sum, 2),
                'credit' => round((float) $row->credit_sum, 2),
            ];
        }

        return $out;
    }

    protected static function postedJoin(int $atelierId, ?string $from, ?string $to)
    {
        $query = DB::table('accounting_lines')
            ->join('accounting_vouchers', 'accounting_vouchers.id', '=', 'accounting_lines.voucher_id')
            ->where('accounting_vouchers.atelier_id', $atelierId)
            ->where('accounting_vouchers.status', AccountingVoucher::STATUS_POSTED);

        if ($from) {
            $query->whereDate('accounting_vouchers.date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('accounting_vouchers.date', '<=', $to);
        }

        return $query;
    }

    protected static function postedLinesQuery(int $atelierId, ?string $from, ?string $to)
    {
        return self::postedJoin($atelierId, $from, $to)->select([
            'accounting_lines.id',
            'accounting_lines.voucher_id',
            'accounting_lines.account_id',
            'accounting_lines.debit',
            'accounting_lines.credit',
            'accounting_lines.description',
            'accounting_lines.sort_order',
            'accounting_vouchers.date as voucher_date',
            'accounting_vouchers.number as voucher_number',
            'accounting_vouchers.description as voucher_description',
            'accounting_vouchers.source_type',
            'accounting_vouchers.reverses_voucher_id',
        ]);
    }

    /**
     * @param  array{debit: float, credit: float}  $pair
     * @param  array{debit_balance: float, credit_balance: float}  $split
     * @return array<string, mixed>
     */
    protected static function accountRow(AccountingAccount $account, array $pair, array $split): array
    {
        return array_merge(self::accountMeta($account), [
            'debit_turnover' => $pair['debit'],
            'credit_turnover' => $pair['credit'],
            'debit_balance' => $split['debit_balance'],
            'credit_balance' => $split['credit_balance'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function accountMeta(AccountingAccount $account): array
    {
        return [
            'account_id' => (int) $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'level' => $account->level,
            'level_label' => $account->levelLabel(),
            'nature' => $account->nature,
            'kind' => $account->kind,
            'linked_type' => $account->linked_type,
            'linked_id' => $account->linked_id,
        ];
    }

    /**
     * @return array{debit_balance: float, credit_balance: float}
     */
    protected static function splitBalance(string $nature, float $debit, float $credit): array
    {
        return self::splitFromSigned($nature, self::signedBalance($nature, $debit, $credit));
    }

    protected static function signedBalance(string $nature, float $debit, float $credit): float
    {
        $debit = round($debit, 2);
        $credit = round($credit, 2);
        if ($nature === AccountingAccount::NATURE_CREDIT) {
            return round($credit - $debit, 2);
        }

        return round($debit - $credit, 2);
    }

    protected static function signedDelta(string $nature, float $debit, float $credit): float
    {
        return self::signedBalance($nature, $debit, $credit);
    }

    /**
     * @return array{debit_balance: float, credit_balance: float}
     */
    protected static function splitFromSigned(string $nature, float $signed): array
    {
        $signed = round($signed, 2);
        if (abs($signed) < 0.01) {
            return ['debit_balance' => 0.0, 'credit_balance' => 0.0];
        }
        if ($nature === AccountingAccount::NATURE_CREDIT) {
            return $signed >= 0
                ? ['debit_balance' => 0.0, 'credit_balance' => $signed]
                : ['debit_balance' => round(abs($signed), 2), 'credit_balance' => 0.0];
        }

        return $signed >= 0
            ? ['debit_balance' => $signed, 'credit_balance' => 0.0]
            : ['debit_balance' => 0.0, 'credit_balance' => round(abs($signed), 2)];
    }

    /**
     * @param  array<string, array{debit: float, credit: float}>  $byCode
     */
    protected static function creditNet(array $byCode, string $code): float
    {
        $pair = $byCode[$code] ?? ['debit' => 0.0, 'credit' => 0.0];

        return round($pair['credit'] - $pair['debit'], 2);
    }

    /**
     * @param  array<string, array{debit: float, credit: float}>  $byCode
     */
    protected static function debitNet(array $byCode, string $code): float
    {
        $pair = $byCode[$code] ?? ['debit' => 0.0, 'credit' => 0.0];

        return round($pair['debit'] - $pair['credit'], 2);
    }

    /**
     * @param  array<int, array{debit: float, credit: float}>  $turnover
     * @return array<int, array<string, mixed>>
     */
    protected static function cashCompare(int $atelierId, array $turnover): array
    {
        $rows = [];
        $cashAccounts = AccountingAccount::query()
            ->forAtelier($atelierId)
            ->where(function ($q) {
                $q->where('linked_type', AccountingAccount::LINK_SHOP_ACCOUNT)
                    ->orWhere('linked_type', AccountingAccount::LINK_TILL);
            })
            ->orderBy('code')
            ->get();

        $shopIds = $cashAccounts
            ->where('linked_type', AccountingAccount::LINK_SHOP_ACCOUNT)
            ->pluck('linked_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
        $operational = $shopIds !== []
            ? ShopAccountBalanceService::balances($atelierId, $shopIds)
            : [];
        $shopNames = $shopIds !== []
            ? ShopAccount::query()->whereIn('id', $shopIds)->pluck('name', 'id')
            : collect();

        foreach ($cashAccounts as $account) {
            $pair = $turnover[(int) $account->id] ?? ['debit' => 0.0, 'credit' => 0.0];
            $ledger = self::signedBalance($account->nature, $pair['debit'], $pair['credit']);
            $ops = null;
            $name = $account->name;
            if ($account->linked_type === AccountingAccount::LINK_SHOP_ACCOUNT && $account->linked_id) {
                $ops = round((float) ($operational[(int) $account->linked_id] ?? 0), 2);
                $name = (string) ($shopNames[(int) $account->linked_id] ?? $account->name);
            }
            $rows[] = [
                'account_id' => (int) $account->id,
                'code' => $account->code,
                'name' => $name,
                'linked_type' => $account->linked_type,
                'shop_account_id' => $account->linked_type === AccountingAccount::LINK_SHOP_ACCOUNT
                    ? (int) $account->linked_id
                    : null,
                'ledger_balance' => $ledger,
                'operational_balance' => $ops,
                'difference' => $ops === null ? null : round($ledger - $ops, 2),
                'note' => $account->linked_type === AccountingAccount::LINK_TILL
                    ? 'صندوق فروش معادل عملیاتی جدا ندارد؛ مانده باید نزدیک وجوه وصول‌نشدهٔ تطبیق باشد.'
                    : null,
            ];
        }

        return $rows;
    }
}
