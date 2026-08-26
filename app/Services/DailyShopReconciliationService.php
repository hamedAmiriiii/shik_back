<?php

namespace App\Services;

use App\Models\DailyShopReconciliation;
use App\Models\DailyShopReconciliationAccountDeposit;
use App\Models\DailyShopReconciliationDeposit;
use App\Models\Purchase;
use App\Models\ShopAccount;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Morilog\Jalali\Jalalian;

class DailyShopReconciliationService
{
    public const EDITABLE_DAYS_BACK = 30;

    /**
     * گرید روزانه یک ماه شمسی (پیش‌فرض: ماه جاری).
     *
     * @return array<string, mixed>
     */
    public static function gridForMonth(int $atelierId, ?int $jalaliYear = null, ?int $jalaliMonth = null): array
    {
        ShopAccount::ensureDefaultsForAtelier($atelierId);

        $now = Jalalian::fromCarbon(Carbon::now('Asia/Tehran'));
        $jalaliYear = $jalaliYear ?? (int) $now->getYear();
        $jalaliMonth = $jalaliMonth ?? (int) $now->getMonth();

        $monthStartJalali = new Jalalian($jalaliYear, $jalaliMonth, 1, 0, 0, 0);
        $daysInMonth = $monthStartJalali->getMonthDays();
        $monthEndJalali = new Jalalian($jalaliYear, $jalaliMonth, $daysInMonth, 23, 59, 59);

        $start = $monthStartJalali->toCarbon()->startOfDay();
        $end = $monthEndJalali->toCarbon()->endOfDay();

        $fromDate = $start->format('Y-m-d');
        $toDate = $end->format('Y-m-d');

        $shopAccounts = self::activeAccounts($atelierId);
        $accountBalances = self::balancesByAccountId($atelierId, $shopAccounts->pluck('id')->all());

        $reconciliations = self::reconciliationsInRange($atelierId, $fromDate, $toDate);
        $earlierDiscrepancySum = self::sumEarlierDiscrepancy($atelierId, $fromDate);

        $cumulative = $earlierDiscrepancySum;
        $daily = [];
        $periodTotalSales = 0.0;

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $dayCarbon = (new Jalalian($jalaliYear, $jalaliMonth, $day, 0, 0, 0))
                ->toCarbon()
                ->startOfDay();
            $dateKey = $dayCarbon->format('Y-m-d');

            $daily[] = self::buildDayRow(
                $atelierId,
                $dayCarbon,
                $dateKey,
                $reconciliations,
                $cumulative,
                $shopAccounts
            );

            $last = $daily[count($daily) - 1];
            $periodTotalSales += (float) $last['total_sales'];
            $cumulative = (float) $last['cumulative_discrepancy'];
        }

        $isCurrentMonth = $jalaliYear === (int) $now->getYear()
            && $jalaliMonth === (int) $now->getMonth();

        return [
            'filter' => [
                'year' => $jalaliYear,
                'month' => $jalaliMonth,
                'month_name' => self::jalaliMonthName($jalaliMonth),
                'is_current_month' => $isCurrentMonth,
            ],
            'days_in_month' => $daysInMonth,
            'editable_days_back' => self::EDITABLE_DAYS_BACK,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'from_date_jalali' => $monthStartJalali->format('Y-m-d'),
            'to_date_jalali' => $monthEndJalali->format('Y-m-d'),
            'period_total_sales' => round($periodTotalSales, 2),
            'opening_cumulative_discrepancy' => round($earlierDiscrepancySum, 2),
            'closing_cumulative_discrepancy' => round($cumulative, 2),
            'shop_accounts' => $shopAccounts->map(fn (ShopAccount $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'sort_order' => (int) $a->sort_order,
                'legacy_slot' => $a->legacy_slot,
                'balance' => round((float) ($accountBalances[$a->id] ?? 0), 2),
            ])->values()->all(),
            'daily' => $daily,
            'rows' => $daily,
        ];
    }

    /**
     * @param  Collection<string, DailyShopReconciliation>  $reconciliations
     * @param  Collection<int, ShopAccount>  $shopAccounts
     * @return array<string, mixed>
     */
    protected static function buildDayRow(
        int $atelierId,
        Carbon $dayCarbon,
        string $dateKey,
        Collection $reconciliations,
        float $cumulative,
        Collection $shopAccounts
    ): array {
        $metrics = ShopSalesReportService::salesAndProfitForDate($atelierId, $dayCarbon);

        $dayKey = $dayCarbon->copy()->setTimezone('Asia/Tehran')->format('Y-m-d');
        $purchasesCount = Purchase::query()
            ->forAtelier($atelierId)
            ->whereDate('created_at', $dayKey)
            ->count();

        $accounts = ShopSalesReportService::accountsBreakdown($metrics);

        $accountDeposits = $shopAccounts->map(fn (ShopAccount $a) => [
            'shop_account_id' => $a->id,
            'name' => $a->name,
            'legacy_slot' => $a->legacy_slot,
            'amount' => 0.0,
            'deposit_record_id' => null,
        ])->values()->all();

        $row = [
            'date' => $dateKey,
            'date_jalali' => Jalalian::fromCarbon($dayCarbon)->format('Y-m-d'),
            'day_of_month' => (int) Jalalian::fromCarbon($dayCarbon)->getDay(),
            'total_sales' => (float) $metrics['sales'],
            'gross_sales' => (float) $metrics['gross_sales'],
            'total_returns' => (float) $metrics['returns'],
            'card_amount' => (float) $metrics['card_amount'],
            'cash_amount' => (float) $metrics['cash_amount'],
            'cash_and_card_total' => (float) $metrics['cash_and_card_total'],
            'installments_collected' => (float) $metrics['installments_collected'],
            'total_collected' => (float) $metrics['total_collected'],
            'discount_given' => (float) $metrics['discount_given'],
            'credit_used_total' => (float) $metrics['credit_used_total'],
            'settlement_total' => (float) $metrics['settlement_total'],
            'accounts' => $accounts,
            'uncollected_installments' => (float) $metrics['uncollected_installments'],
            'uncollected_debts' => (float) ($metrics['uncollected_debts'] ?? 0),
            'uncollected_cheques' => (float) ($metrics['uncollected_cheques'] ?? 0),
            'cheque_payments' => (float) ($metrics['cheque_payments'] ?? 0),
            'cheques_collected' => (float) ($metrics['cheques_collected'] ?? 0),
            'open_debt' => (float) ($metrics['open_debt'] ?? $metrics['uncollected_debts'] ?? 0),
            'open_cheques' => (float) ($metrics['open_cheques'] ?? 0),
            'debts_collected' => (float) ($metrics['debts_collected'] ?? 0),
            'purchases_count' => $purchasesCount,
            'deposit_account_1' => 0.0,
            'deposit_account_2' => 0.0,
            'deposit_cash' => 0.0,
            'account_deposits' => $accountDeposits,
            'deposited_total' => 0.0,
            'daily_discrepancy' => null,
            'cumulative_discrepancy' => round($cumulative, 2),
            'editable' => self::isDateEditable($dateKey),
            'is_closed' => false,
            'notes' => null,
            'user_name' => null,
            'reconciliation_id' => null,
            'deposit_ids' => null,
        ];

        $recon = $reconciliations->get($dateKey);
        if ($recon) {
            // اگر محاسبهٔ زنده فروش ۰ بود ولی اسنپ‌شات روز مقدار دارد، از اسنپ‌شات استفاده کن
            if ((float) $row['total_sales'] <= 0 && (float) $recon->total_sales > 0) {
                $row['total_sales'] = (float) $recon->total_sales;
                $row['card_amount'] = (float) $recon->card_amount;
                $row['cash_amount'] = (float) $recon->cash_amount;
                $row['installments_collected'] = (float) $recon->installments_collected;
                $row['total_collected'] = (float) $recon->total_collected;
                $row['credit_used_total'] = (float) $recon->credit_used_total;
                $row['settlement_total'] = (float) $recon->settlement_total;
                $row['discount_given'] = (float) ($recon->discount_given ?? 0);
                $row['accounts'] = ShopSalesReportService::accountsBreakdown([
                    'sales' => $row['total_sales'],
                    'card_amount' => $row['card_amount'],
                    'cash_amount' => $row['cash_amount'],
                    'installments_collected' => $row['installments_collected'],
                    'total_collected' => $row['total_collected'],
                    'credit_used_total' => $row['credit_used_total'],
                    'settlement_total' => $row['settlement_total'],
                    'discount_given' => $row['discount_given'],
                    'debts_collected' => $row['debts_collected'],
                    'cheques_collected' => $row['cheques_collected'],
                    'cheque_payments' => $row['cheque_payments'],
                    'uncollected_installments' => $row['uncollected_installments'],
                    'uncollected_debts' => $row['uncollected_debts'],
                    'uncollected_cheques' => $row['uncollected_cheques'],
                    'open_debt' => $row['open_debt'],
                    'open_cheques' => $row['open_cheques'],
                ]);
            }

            $savedDeposits = self::accountDepositsForReconciliation($recon, $shopAccounts);
            $row['account_deposits'] = $savedDeposits;
            $row['deposit_account_1'] = self::amountForLegacySlot($savedDeposits, ShopAccount::LEGACY_ACCOUNT_1, (float) $recon->deposit_account_1);
            $row['deposit_account_2'] = self::amountForLegacySlot($savedDeposits, ShopAccount::LEGACY_ACCOUNT_2, (float) $recon->deposit_account_2);
            $row['deposit_cash'] = (float) $recon->deposit_cash;
            $row['deposited_total'] = (float) $recon->deposited_total;
            $row['daily_discrepancy'] = (float) $recon->daily_discrepancy;
            $row['is_closed'] = true;
            $row['notes'] = $recon->notes;
            $row['user_name'] = $recon->user_name;
            $row['reconciliation_id'] = $recon->id;
            $row['deposit_ids'] = [
                'account_1' => $recon->deposit_record_account_1_id,
                'account_2' => $recon->deposit_record_account_2_id,
                'cash' => $recon->deposit_record_cash_id,
                'by_account' => collect($savedDeposits)
                    ->mapWithKeys(fn ($d) => [$d['shop_account_id'] => $d['deposit_record_id']])
                    ->all(),
            ];
            $cumulative += (float) $recon->daily_discrepancy;
            $row['cumulative_discrepancy'] = round($cumulative, 2);
            $row['updated_at'] = $recon->updated_at
                ? Jalalian::fromCarbon(
                    Carbon::parse($recon->getRawOriginal('updated_at'))->setTimezone('Asia/Tehran')
                )->format('Y-m-d H:i:s')
                : null;
        }

        return $row;
    }

    /**
     * @param  Collection<int, ShopAccount>  $shopAccounts
     * @return array<int, array<string, mixed>>
     */
    protected static function accountDepositsForReconciliation(
        DailyShopReconciliation $recon,
        Collection $shopAccounts
    ): array {
        $byAccountId = collect();
        if (Schema::hasTable('daily_shop_reconciliation_account_deposits')) {
            $byAccountId = DailyShopReconciliationAccountDeposit::query()
                ->where('reconciliation_id', $recon->id)
                ->get()
                ->keyBy('shop_account_id');
        }

        return $shopAccounts->map(function (ShopAccount $account) use ($recon, $byAccountId) {
            $line = $byAccountId->get($account->id);

            $legacyAmount = 0.0;
            $legacyDepositRecordId = null;
            if ($account->legacy_slot === ShopAccount::LEGACY_ACCOUNT_1) {
                $legacyAmount = (float) $recon->deposit_account_1;
                $legacyDepositRecordId = $recon->deposit_record_account_1_id;
            } elseif ($account->legacy_slot === ShopAccount::LEGACY_ACCOUNT_2) {
                $legacyAmount = (float) $recon->deposit_account_2;
                $legacyDepositRecordId = $recon->deposit_record_account_2_id;
            }

            if ($line) {
                $amount = (float) $line->amount;
                // اگر ردیف جدید خالی است ولی ستون قدیمی مقدار دارد
                if ($amount <= 0 && $legacyAmount > 0) {
                    $amount = $legacyAmount;
                }

                return [
                    'shop_account_id' => $account->id,
                    'name' => $account->name,
                    'legacy_slot' => $account->legacy_slot,
                    'amount' => $amount,
                    'deposit_record_id' => $line->deposit_record_id ?: $legacyDepositRecordId,
                ];
            }

            return [
                'shop_account_id' => $account->id,
                'name' => $account->name,
                'legacy_slot' => $account->legacy_slot,
                'amount' => $legacyAmount,
                'deposit_record_id' => $legacyDepositRecordId,
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $deposits
     */
    protected static function amountForLegacySlot(array $deposits, string $slot, float $fallback): float
    {
        foreach ($deposits as $deposit) {
            if (($deposit['legacy_slot'] ?? null) === $slot) {
                return (float) $deposit['amount'];
            }
        }

        return $fallback;
    }

    protected static function jalaliMonthName(int $month): string
    {
        $months = [
            1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر',
            5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان',
            9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
        ];

        return $months[$month] ?? '';
    }

    /**
     * @return Collection<string, DailyShopReconciliation>
     */
    protected static function reconciliationsInRange(int $atelierId, string $fromDate, string $toDate): Collection
    {
        if (! Schema::hasTable('daily_shop_reconciliations')) {
            return collect();
        }

        return DailyShopReconciliation::query()
            ->where('atelier_id', $atelierId)
            ->whereBetween('date', [$fromDate, $toDate])
            ->when(
                Schema::hasTable('daily_shop_reconciliation_account_deposits'),
                fn ($q) => $q->with(['accountDeposits'])
            )
            ->get()
            ->keyBy(fn (DailyShopReconciliation $r) => Carbon::parse($r->getRawOriginal('date'))->format('Y-m-d'));
    }

    protected static function sumEarlierDiscrepancy(int $atelierId, string $fromDate): float
    {
        if (! Schema::hasTable('daily_shop_reconciliations')) {
            return 0.0;
        }

        return (float) DailyShopReconciliation::query()
            ->where('atelier_id', $atelierId)
            ->where('date', '<', $fromDate)
            ->sum('daily_discrepancy');
    }

    /**
     * @return Collection<int, ShopAccount>
     */
    public static function activeAccounts(int $atelierId): Collection
    {
        if (! Schema::hasTable('shop_accounts')) {
            return collect();
        }

        ShopAccount::ensureDefaultsForAtelier($atelierId);

        return ShopAccount::query()
            ->forAtelier($atelierId)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<int>  $accountIds
     * @return array<int, float>
     */
    public static function balancesByAccountId(int $atelierId, array $accountIds): array
    {
        $balances = array_fill_keys($accountIds, 0.0);
        if ($accountIds === []) {
            return [];
        }

        if (Schema::hasTable('daily_shop_reconciliation_account_deposits')) {
            $fromLines = DailyShopReconciliationAccountDeposit::query()
                ->whereIn('shop_account_id', $accountIds)
                ->whereHas('shopAccount', fn ($q) => $q->where('atelier_id', $atelierId))
                ->selectRaw('shop_account_id, SUM(amount) as total')
                ->groupBy('shop_account_id')
                ->pluck('total', 'shop_account_id')
                ->map(fn ($v) => (float) $v)
                ->all();

            foreach ($fromLines as $id => $total) {
                $balances[(int) $id] = $total;
            }
        }

        // تکمیل از ستون‌های قدیمی برای روزهایی که هنوز ردیف جدید ندارند (یا مبلغ‌شان ۰ است)
        if (Schema::hasTable('daily_shop_reconciliations') && Schema::hasTable('shop_accounts')) {
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
                    ->where(function ($q) use ($account) {
                        $q->whereNotExists(function ($sub) use ($account) {
                            $sub->select(DB::raw(1))
                                ->from('daily_shop_reconciliation_account_deposits as d')
                                ->whereColumn('d.reconciliation_id', 'r.id')
                                ->where('d.shop_account_id', $account->id)
                                ->where('d.amount', '>', 0);
                        });
                    })
                    ->sum("r.{$column}");

                if ($legacySum > 0) {
                    $balances[$account->id] = round(($balances[$account->id] ?? 0) + $legacySum, 2);
                }
            }
        }

        return $balances;
    }

    /**
     * ثبت یا ویرایش تطبیق یک روز — واریز به حساب‌های فروشگاه + نقدی.
     *
     * @param  array{
     *   account_deposits?: array<int, array{shop_account_id: int|string, amount: float|int|string}>,
     *   deposit_account_1?: float|int|string,
     *   deposit_account_2?: float|int|string,
     *   deposit_cash: float|int|string,
     *   notes?: ?string
     * }  $deposits
     */
    public static function upsert(
        int $atelierId,
        string $dateKey,
        array $deposits,
        string $userName,
        ?string $notes = null
    ): DailyShopReconciliation {
        if (! Schema::hasTable('daily_shop_reconciliations')) {
            throw new \RuntimeException(
                'جدول daily_shop_reconciliations وجود ندارد. migration یا فایل SQL را اجرا کنید.'
            );
        }

        if (! Schema::hasTable('daily_shop_reconciliation_deposits')) {
            throw new \RuntimeException(
                'جدول daily_shop_reconciliation_deposits وجود ندارد. migration یا فایل SQL را اجرا کنید.'
            );
        }

        if (! self::isDateEditable($dateKey)) {
            throw new \InvalidArgumentException(
                'فقط تا '.self::EDITABLE_DAYS_BACK.' روز قبل از امروز قابل ویرایش است.'
            );
        }

        ShopAccount::ensureDefaultsForAtelier($atelierId);
        $shopAccounts = self::activeAccounts($atelierId);
        if ($shopAccounts->isEmpty()) {
            throw new \RuntimeException('هیچ حساب فعالی برای فروشگاه تعریف نشده است.');
        }

        $resolvedAccountAmounts = self::resolveAccountAmounts($atelierId, $shopAccounts, $deposits);
        $depositCash = round((float) ($deposits['deposit_cash'] ?? 0), 2);
        $accountsTotal = round(array_sum($resolvedAccountAmounts), 2);

        $dateCarbon = Carbon::parse($dateKey, 'Asia/Tehran')->startOfDay();
        $dateGregorian = $dateCarbon->format('Y-m-d');

        // واریز حساب‌های غیرفعالِ همان روز (برای حفظ داده و محاسبهٔ جمع)
        $inactivePreservedTotal = 0.0;
        $existingRecon = DailyShopReconciliation::query()
            ->where('atelier_id', $atelierId)
            ->whereDate('date', $dateGregorian)
            ->first();
        if ($existingRecon && Schema::hasTable('daily_shop_reconciliation_account_deposits')) {
            $activeIds = $shopAccounts->pluck('id')->all();
            $inactivePreservedTotal = (float) DailyShopReconciliationAccountDeposit::query()
                ->where('reconciliation_id', $existingRecon->id)
                ->when($activeIds !== [], fn ($q) => $q->whereNotIn('shop_account_id', $activeIds))
                ->sum('amount');
        }

        $depositedTotal = round($accountsTotal + $depositCash + $inactivePreservedTotal, 2);

        $metrics = ShopSalesReportService::salesAndProfitForDate($atelierId, $dateCarbon);
        $totalCollected = (float) $metrics['total_collected'];
        $dailyDiscrepancy = round($depositedTotal - $totalCollected, 2);

        $dateJalali = Jalalian::fromCarbon($dateCarbon)->format('Y-m-d');

        $legacy1 = 0.0;
        $legacy2 = 0.0;
        foreach ($shopAccounts as $account) {
            $amount = $resolvedAccountAmounts[$account->id] ?? 0.0;
            if ($account->legacy_slot === ShopAccount::LEGACY_ACCOUNT_1) {
                $legacy1 = $amount;
            } elseif ($account->legacy_slot === ShopAccount::LEGACY_ACCOUNT_2) {
                $legacy2 = $amount;
            }
        }

        return DB::transaction(function () use (
            $atelierId,
            $dateGregorian,
            $dateJalali,
            $metrics,
            $shopAccounts,
            $resolvedAccountAmounts,
            $legacy1,
            $legacy2,
            $depositCash,
            $depositedTotal,
            $totalCollected,
            $dailyDiscrepancy,
            $userName,
            $notes
        ) {
            $recon = DailyShopReconciliation::query()
                ->where('atelier_id', $atelierId)
                ->whereDate('date', $dateGregorian)
                ->first();

            $payload = [
                'atelier_id' => $atelierId,
                'date' => $dateGregorian,
                'total_sales' => $metrics['sales'],
                'card_amount' => $metrics['card_amount'],
                'cash_amount' => $metrics['cash_amount'],
                'installments_collected' => $metrics['installments_collected'],
                'total_collected' => $totalCollected,
                'credit_used_total' => $metrics['credit_used_total'],
                'settlement_total' => $metrics['settlement_total'],
                'discount_given' => $metrics['discount_given'],
                'deposit_account_1' => $legacy1,
                'deposit_account_2' => $legacy2,
                'deposit_cash' => $depositCash,
                'deposited_total' => $depositedTotal,
                'daily_discrepancy' => $dailyDiscrepancy,
                'notes' => $notes,
                'user_name' => $userName,
            ];

            if ($recon) {
                $recon->fill($payload);
            } else {
                $recon = new DailyShopReconciliation($payload);
            }

            // نقدی (مثل قبل)
            if ($depositCash > 0) {
                $recon->deposit_record_cash_id = self::syncDeposit(
                    $recon->deposit_record_cash_id,
                    $atelierId,
                    $dateGregorian,
                    $depositCash,
                    'واریز روزانه '.$dateJalali.' — نقدی',
                    $userName,
                    $notes,
                    null
                );
            } else {
                if ($recon->deposit_record_cash_id) {
                    DailyShopReconciliationDeposit::where('id', $recon->deposit_record_cash_id)->delete();
                }
                $recon->deposit_record_cash_id = null;
            }

            $recon->save();

            $existingLines = Schema::hasTable('daily_shop_reconciliation_account_deposits')
                ? DailyShopReconciliationAccountDeposit::query()
                    ->where('reconciliation_id', $recon->id)
                    ->get()
                    ->keyBy('shop_account_id')
                : collect();

            foreach ($shopAccounts as $account) {
                $amount = round((float) ($resolvedAccountAmounts[$account->id] ?? 0), 2);
                $line = $existingLines->get($account->id);
                $depositRecordId = $line ? $line->deposit_record_id : null;

                // سازگاری با ستون‌های قدیمی
                if (! $depositRecordId) {
                    if ($account->legacy_slot === ShopAccount::LEGACY_ACCOUNT_1) {
                        $depositRecordId = $recon->deposit_record_account_1_id;
                    } elseif ($account->legacy_slot === ShopAccount::LEGACY_ACCOUNT_2) {
                        $depositRecordId = $recon->deposit_record_account_2_id;
                    }
                }

                if ($amount > 0) {
                    $depositRecordId = self::syncDeposit(
                        $depositRecordId,
                        $atelierId,
                        $dateGregorian,
                        $amount,
                        'واریز روزانه '.$dateJalali.' — '.$account->name,
                        $userName,
                        $notes,
                        $account->id
                    );

                    if (Schema::hasTable('daily_shop_reconciliation_account_deposits')) {
                        DailyShopReconciliationAccountDeposit::query()->updateOrCreate(
                            [
                                'reconciliation_id' => $recon->id,
                                'shop_account_id' => $account->id,
                            ],
                            [
                                'amount' => $amount,
                                'deposit_record_id' => $depositRecordId,
                            ]
                        );
                    }
                } else {
                    if ($depositRecordId) {
                        DailyShopReconciliationDeposit::where('id', $depositRecordId)->delete();
                    }
                    if ($line) {
                        $line->delete();
                    }
                    $depositRecordId = null;
                }

                if ($account->legacy_slot === ShopAccount::LEGACY_ACCOUNT_1) {
                    $recon->deposit_record_account_1_id = $depositRecordId;
                } elseif ($account->legacy_slot === ShopAccount::LEGACY_ACCOUNT_2) {
                    $recon->deposit_record_account_2_id = $depositRecordId;
                }
            }

            // خطوط حساب‌های غیرفعال دست‌نخورده می‌مانند تا دادهٔ قبلی حفظ شود

            $recon->save();

            return $recon->fresh(['accountDeposits']);
        });
    }

    /**
     * @param  Collection<int, ShopAccount>  $shopAccounts
     * @param  array<string, mixed>  $deposits
     * @return array<int, float> shop_account_id => amount
     */
    protected static function resolveAccountAmounts(int $atelierId, Collection $shopAccounts, array $deposits): array
    {
        $amounts = [];
        foreach ($shopAccounts as $account) {
            $amounts[$account->id] = 0.0;
        }

        if (! empty($deposits['account_deposits']) && is_array($deposits['account_deposits'])) {
            $validIds = $shopAccounts->pluck('id')->all();
            foreach ($deposits['account_deposits'] as $row) {
                $accountId = (int) ($row['shop_account_id'] ?? 0);
                if (! in_array($accountId, $validIds, true)) {
                    throw new \InvalidArgumentException('شناسه حساب نامعتبر است: '.$accountId);
                }
                $amounts[$accountId] = round((float) ($row['amount'] ?? 0), 2);
            }

            return $amounts;
        }

        // سازگاری با API قدیمی: deposit_account_1 / deposit_account_2
        foreach ($shopAccounts as $account) {
            if ($account->legacy_slot === ShopAccount::LEGACY_ACCOUNT_1 && array_key_exists('deposit_account_1', $deposits)) {
                $amounts[$account->id] = round((float) $deposits['deposit_account_1'], 2);
            }
            if ($account->legacy_slot === ShopAccount::LEGACY_ACCOUNT_2 && array_key_exists('deposit_account_2', $deposits)) {
                $amounts[$account->id] = round((float) $deposits['deposit_account_2'], 2);
            }
        }

        return $amounts;
    }

    public static function isDateEditable(string $dateKey): bool
    {
        $date = Carbon::parse($dateKey, 'Asia/Tehran')->startOfDay();
        $today = Carbon::now('Asia/Tehran')->startOfDay();
        $min = $today->copy()->subDays(self::EDITABLE_DAYS_BACK);

        return $date->gte($min) && $date->lte($today);
    }

    protected static function syncDeposit(
        ?int $depositRecordId,
        int $atelierId,
        string $dateGregorian,
        float $amount,
        string $title,
        string $userName,
        ?string $notes,
        ?int $shopAccountId = null
    ): int {
        $fields = [
            'amount' => $amount,
            'title' => $title,
            'description' => $notes,
            'date' => $dateGregorian,
            'user_name' => $userName,
            'atelier_id' => $atelierId,
        ];

        if ($shopAccountId !== null && Schema::hasColumn('daily_shop_reconciliation_deposits', 'shop_account_id')) {
            $fields['shop_account_id'] = $shopAccountId;
        }

        if ($depositRecordId) {
            $deposit = DailyShopReconciliationDeposit::find($depositRecordId);
            if ($deposit) {
                $deposit->update($fields);

                return (int) $deposit->id;
            }
        }

        $deposit = DailyShopReconciliationDeposit::create($fields);

        return (int) $deposit->id;
    }
}
