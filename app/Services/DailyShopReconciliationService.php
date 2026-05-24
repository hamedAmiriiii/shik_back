<?php

namespace App\Services;

use App\Models\DailyShopReconciliation;
use App\Models\Invoice;
use App\Models\Purchase;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Morilog\Jalali\Jalalian;

class DailyShopReconciliationService
{
    public const EDITABLE_DAYS_BACK = 3;

    private const DEPOSIT_SLOTS = [
        'deposit_account_1' => ['invoice_column' => 'invoice_account_1_id', 'label' => 'حساب ۱'],
        'deposit_account_2' => ['invoice_column' => 'invoice_account_2_id', 'label' => 'حساب ۲'],
        'deposit_cash' => ['invoice_column' => 'invoice_cash_id', 'label' => 'نقدی'],
    ];

    /**
     * گرید روزانه یک ماه شمسی (پیش‌فرض: ماه جاری).
     *
     * @return array<string, mixed>
     */
    public static function gridForMonth(int $atelierId, ?int $jalaliYear = null, ?int $jalaliMonth = null): array
    {
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
                $cumulative
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
            'daily' => $daily,
            'rows' => $daily,
        ];
    }

    /**
     * @param  Collection<string, DailyShopReconciliation>  $reconciliations
     * @return array<string, mixed>
     */
    protected static function buildDayRow(
        int $atelierId,
        Carbon $dayCarbon,
        string $dateKey,
        Collection $reconciliations,
        float $cumulative
    ): array {
        $metrics = ShopSalesReportService::salesAndProfitForDate($atelierId, $dayCarbon);

        $rangeStart = $dayCarbon->copy()->startOfDay()->format('Y-m-d H:i:s');
        $rangeEnd = $dayCarbon->copy()->endOfDay()->format('Y-m-d H:i:s');
        $purchasesCount = Purchase::query()
            ->forAtelier($atelierId)
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->count();

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
            'uncollected_installments' => (float) $metrics['uncollected_installments'],
            'purchases_count' => $purchasesCount,
            'deposit_account_1' => 0.0,
            'deposit_account_2' => 0.0,
            'deposit_cash' => 0.0,
            'deposited_total' => 0.0,
            'daily_discrepancy' => null,
            'cumulative_discrepancy' => round($cumulative, 2),
            'editable' => self::isDateEditable($dateKey),
            'is_closed' => false,
            'notes' => null,
            'user_name' => null,
            'reconciliation_id' => null,
            'invoice_ids' => null,
        ];

        $recon = $reconciliations->get($dateKey);
        if ($recon) {
            $row['deposit_account_1'] = (float) $recon->deposit_account_1;
            $row['deposit_account_2'] = (float) $recon->deposit_account_2;
            $row['deposit_cash'] = (float) $recon->deposit_cash;
            $row['deposited_total'] = (float) $recon->deposited_total;
            $row['daily_discrepancy'] = (float) $recon->daily_discrepancy;
            $row['is_closed'] = true;
            $row['notes'] = $recon->notes;
            $row['user_name'] = $recon->user_name;
            $row['reconciliation_id'] = $recon->id;
            $row['invoice_ids'] = [
                'account_1' => $recon->invoice_account_1_id,
                'account_2' => $recon->invoice_account_2_id,
                'cash' => $recon->invoice_cash_id,
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
     * ثبت یا ویرایش تطبیق یک روز — همراه با سه سند Invoice.
     *
     * @param  array{deposit_account_1: float|int|string, deposit_account_2: float|int|string, deposit_cash: float|int|string, notes?: ?string}  $deposits
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

        if (! self::isDateEditable($dateKey)) {
            throw new \InvalidArgumentException(
                'فقط تا '.self::EDITABLE_DAYS_BACK.' روز قبل از امروز قابل ویرایش است.'
            );
        }

        $dateCarbon = Carbon::parse($dateKey, 'Asia/Tehran')->startOfDay();
        $metrics = ShopSalesReportService::salesAndProfitForDate($atelierId, $dateCarbon);

        $depositAccount1 = round((float) ($deposits['deposit_account_1'] ?? 0), 2);
        $depositAccount2 = round((float) ($deposits['deposit_account_2'] ?? 0), 2);
        $depositCash = round((float) ($deposits['deposit_cash'] ?? 0), 2);
        $depositedTotal = round($depositAccount1 + $depositAccount2 + $depositCash, 2);
        $totalCollected = (float) $metrics['total_collected'];
        $dailyDiscrepancy = round($depositedTotal - $totalCollected, 2);

        $dateGregorian = $dateCarbon->format('Y-m-d');
        $dateJalali = Jalalian::fromCarbon($dateCarbon)->format('Y-m-d');

        return DB::transaction(function () use (
            $atelierId,
            $dateGregorian,
            $dateJalali,
            $metrics,
            $depositAccount1,
            $depositAccount2,
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
                'deposit_account_1' => $depositAccount1,
                'deposit_account_2' => $depositAccount2,
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

            $amounts = [
                'deposit_account_1' => $depositAccount1,
                'deposit_account_2' => $depositAccount2,
                'deposit_cash' => $depositCash,
            ];

            foreach (self::DEPOSIT_SLOTS as $depositKey => $slot) {
                $invoiceColumn = $slot['invoice_column'];
                $amount = $amounts[$depositKey];
                $invoiceId = $recon->{$invoiceColumn};

                if ($amount > 0) {
                    $recon->{$invoiceColumn} = self::syncInvoice(
                        $invoiceId,
                        $atelierId,
                        $dateGregorian,
                        $amount,
                        'واریز روزانه '.$dateJalali.' — '.$slot['label'],
                        $userName,
                        $notes
                    );
                } else {
                    if ($invoiceId) {
                        Invoice::where('id', $invoiceId)->delete();
                    }
                    $recon->{$invoiceColumn} = null;
                }
            }

            $recon->save();

            return $recon->fresh();
        });
    }

    public static function isDateEditable(string $dateKey): bool
    {
        $date = Carbon::parse($dateKey, 'Asia/Tehran')->startOfDay();
        $today = Carbon::now('Asia/Tehran')->startOfDay();
        $min = $today->copy()->subDays(self::EDITABLE_DAYS_BACK);

        return $date->gte($min) && $date->lte($today);
    }

    protected static function syncInvoice(
        ?int $invoiceId,
        int $atelierId,
        string $dateGregorian,
        float $amount,
        string $title,
        string $userName,
        ?string $notes
    ): int {
        $fields = [
            'amount' => $amount,
            'title' => $title,
            'description' => $notes,
            'date' => $dateGregorian,
            'user_name' => $userName,
            'atelier_id' => $atelierId,
        ];

        if ($invoiceId) {
            $invoice = Invoice::find($invoiceId);
            if ($invoice) {
                $invoice->update($fields);

                return (int) $invoice->id;
            }
        }

        $invoice = Invoice::create($fields);

        return (int) $invoice->id;
    }
}
