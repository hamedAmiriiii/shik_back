<?php

namespace App\Services;

use App\Models\AccountingVoucher;
use Carbon\Carbon;
use Morilog\Jalali\Jalalian;
use RuntimeException;

/**
 * بستن حساب‌های موقت (درآمد/تخفیف/بها/هزینه) به سود انباشته ۳۵.
 * سال مالی یا میان‌دوره: تاریخ سند = as_of؛ قفل ثبت تا همان روز.
 */
class AccountingPeriodCloseService
{
    public const MODE_YEAR = 'year';

    public const MODE_MID = 'mid';

    /**
     * آخرین روز بسته‌شده (میلادی) یا null.
     */
    public static function closedThrough(int $atelierId, ?string $asOfGregorian = null): ?string
    {
        if ($atelierId <= 0 || ! AccountingVoucher::tablesReady()) {
            return null;
        }

        $query = AccountingVoucher::query()
            ->forAtelier($atelierId)
            ->posted()
            ->where('source_type', AccountingVoucher::SOURCE_YEAR_CLOSE);
        if ($asOfGregorian) {
            $query->whereDate('date', '<=', $asOfGregorian);
        }
        $date = $query->max('date');
        if (! $date) {
            return null;
        }

        return Carbon::parse($date)->toDateString();
    }

    /**
     * شروع دورهٔ باز: روز بعد از آخرین بستن. اگر هنوز بسته‌ای نباشد null.
     */
    public static function openPeriodStartGregorian(int $atelierId): ?Carbon
    {
        $closed = self::closedThrough($atelierId);
        if (! $closed) {
            return null;
        }

        return Carbon::parse($closed)->addDay()->startOfDay();
    }

    /**
     * @return array{closed_through: ?string, start: ?string, today: string}
     */
    public static function openPeriodMeta(int $atelierId): array
    {
        $closed = self::closedThrough($atelierId);
        $today = Jalalian::fromCarbon(Carbon::now('Asia/Tehran'))->format('Y-m-d');
        $start = $closed
            ? Jalalian::fromCarbon(Carbon::parse($closed)->addDay())->format('Y-m-d')
            : null;

        return [
            'closed_through' => $closed
                ? Jalalian::fromCarbon(Carbon::parse($closed))->format('Y-m-d')
                : null,
            'start' => $start,
            'today' => $today,
        ];
    }

    public static function assertDateUnlocked(int $atelierId, $date): void
    {
        $dateString = AccountingLedger::eventDate($date);
        $closed = self::closedThrough($atelierId);
        if ($closed && $dateString <= $closed) {
            $jalali = Jalalian::fromCarbon(Carbon::parse($closed))->format('Y-m-d');
            throw new RuntimeException('دوره تا '.$jalali.' بسته است؛ ثبت سند در این بازه مجاز نیست.');
        }
    }

    public static function assertReversible(AccountingVoucher $voucher): void
    {
        $atelierId = (int) $voucher->atelier_id;
        $dateString = AccountingLedger::eventDate($voucher->date);

        if ($voucher->source_type === AccountingVoucher::SOURCE_YEAR_CLOSE) {
            $latest = self::latestPosted($atelierId);
            if (! $latest || (int) $latest->id !== (int) $voucher->id) {
                throw new RuntimeException('فقط آخرین سند بستن دوره را می‌توان برگشت زد.');
            }

            return;
        }

        $closed = self::closedThrough($atelierId);
        if ($closed && $dateString <= $closed) {
            $jalali = Jalalian::fromCarbon(Carbon::parse($closed))->format('Y-m-d');
            throw new RuntimeException('برگشت سند در دورهٔ بسته‌شده تا '.$jalali.' مجاز نیست.');
        }
    }

    public static function latestPosted(int $atelierId): ?AccountingVoucher
    {
        if (! AccountingVoucher::tablesReady()) {
            return null;
        }

        return AccountingVoucher::query()
            ->forAtelier($atelierId)
            ->posted()
            ->where('source_type', AccountingVoucher::SOURCE_YEAR_CLOSE)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->with(['lines.account'])
            ->first();
    }

    /**
     * سود جاری ترازنامه: از روز بعد از آخرین بستنِ تا as_of.
     */
    public static function currentProfitFrom(int $atelierId, string $asOfGregorian): ?string
    {
        $closed = self::closedThrough($atelierId, $asOfGregorian);
        if (! $closed) {
            return null;
        }

        $from = Carbon::parse($closed)->addDay()->toDateString();
        if ($from > $asOfGregorian) {
            return '__empty__';
        }

        return $from;
    }

    /**
     * @return array<string, mixed>
     */
    public static function status(int $atelierId): array
    {
        ChartOfAccountsSeeder::ensureForAtelier($atelierId);
        $closed = self::closedThrough($atelierId);
        $latest = self::latestPosted($atelierId);
        $history = [];
        if (AccountingVoucher::tablesReady()) {
            $rows = AccountingVoucher::query()
                ->forAtelier($atelierId)
                ->posted()
                ->where('source_type', AccountingVoucher::SOURCE_YEAR_CLOSE)
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get();
            foreach ($rows as $row) {
                $history[] = $row->toApiArray(false);
            }
        }

        $todayJ = Jalalian::fromCarbon(Carbon::now('Asia/Tehran'));

        return [
            'closed_through' => $closed ? Jalalian::fromCarbon(Carbon::parse($closed))->format('Y-m-d') : null,
            'latest' => $latest ? $latest->toApiArray() : null,
            'history' => $history,
            'default_year' => (int) $todayJ->getYear(),
            'today' => $todayJ->format('Y-m-d'),
            'jalali_year_end' => self::jalaliYearEndDate((int) $todayJ->getYear()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function preview(int $atelierId, string $mode, ?int $year, ?string $asOfJalali): array
    {
        $resolved = self::resolveAsOf($mode, $year, $asOfJalali);
        $asOfG = $resolved['as_of_gregorian'];
        $asOfJ = $resolved['as_of_jalali'];
        $yearResolved = $resolved['year'];
        $modeResolved = $resolved['mode'];

        ChartOfAccountsSeeder::ensureForAtelier($atelierId);

        $periodFromG = self::periodFromGregorian($atelierId, $asOfG);
        $periodFromJ = $periodFromG
            ? Jalalian::fromCarbon(Carbon::parse($periodFromG))->format('Y-m-d')
            : null;

        $todayG = Carbon::now('Asia/Tehran')->toDateString();
        $closed = self::closedThrough($atelierId);
        $closedJ = $closed ? Jalalian::fromCarbon(Carbon::parse($closed))->format('Y-m-d') : null;

        $trial = AccountingReportService::trialBalance($atelierId, $periodFromJ, $asOfJ);
        $pnl = AccountingReportService::profitLoss($atelierId, $periodFromJ, $asOfJ);
        $lines = self::buildLines($atelierId, $pnl);
        $net = (float) $pnl['net_profit'];

        $steps = [];
        $steps[] = self::step(
            'range',
            'تعیین دوره',
            true,
            $modeResolved === self::MODE_YEAR
                ? 'بستن سال '.$yearResolved.' تا '.$asOfJ
                : 'بستن میان‌دوره تا '.$asOfJ,
            $periodFromJ
                ? 'گردش از '.$periodFromJ.' (روز بعد از آخرین بستن) تا '.$asOfJ
                : 'گردش از ابتدای دفتر تا '.$asOfJ
        );

        $notFuture = $asOfG <= $todayG;
        $steps[] = self::step(
            'not_future',
            'تاریخ بستن از امروز جلوتر نباشد',
            $notFuture,
            $notFuture ? 'تاریخ بستن معتبر است' : 'نمی‌توان برای تاریخ آینده بستن زد'
        );

        $afterPrevious = ! $closed || $asOfG > $closed;
        $steps[] = self::step(
            'after_previous',
            'بعد از آخرین دورهٔ بسته‌شده',
            $afterPrevious,
            $afterPrevious
                ? ($closedJ ? 'آخرین بستن تا '.$closedJ.' بوده است' : 'هنوز دوره‌ای بسته نشده')
                : 'این تاریخ داخل دورهٔ بسته‌شده تا '.$closedJ.' است'
        );

        $trialOk = (bool) ($trial['balanced'] ?? false);
        $steps[] = self::step(
            'trial_balance',
            'تراز آزمایشی دوره متوازن باشد',
            $trialOk,
            $trialOk ? 'گردش و مانده متوازن است' : 'تراز آزمایشی نامتوازن است؛ بستن ممکن نیست'
        );

        $hasActivity = abs($net) >= 0.01 || count($lines) >= 2;
        $steps[] = self::step(
            'activity',
            'حساب موقت برای بستن وجود داشته باشد',
            $hasActivity,
            $hasActivity
                ? (abs($net) >= 0.01
                    ? 'سود/زیان خالص دوره: '.number_format($net, 0).' تومان'
                    : 'گردش موقت صفر نیست')
                : 'در این بازه ماندهٔ درآمد/هزینه برای بستن نیست'
        );

        $retainedOk = true;
        $retainedDetail = 'حساب ۳۵ سود انباشته در کدینگ هست';
        try {
            AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_RETAINED);
        } catch (RuntimeException $e) {
            $retainedOk = false;
            $retainedDetail = $e->getMessage();
        }
        $steps[] = self::step(
            'retained',
            'حساب سود انباشته',
            $retainedOk,
            $retainedDetail
        );

        $canClose = $notFuture && $afterPrevious && $trialOk && $hasActivity && $retainedOk;
        foreach ($steps as $step) {
            if (! $step['ok'] && in_array($step['key'], ['not_future', 'after_previous', 'trial_balance', 'activity', 'retained'], true)) {
                $canClose = false;
            }
        }

        $existing = AccountingVoucherService::findPosted(
            $atelierId,
            AccountingVoucher::SOURCE_YEAR_CLOSE,
            $resolved['source_id']
        );

        return [
            'mode' => $modeResolved,
            'year' => $yearResolved,
            'as_of' => $asOfJ,
            'period_from' => $periodFromJ,
            'closed_through' => $closedJ,
            'can_close' => $canClose && ! $existing,
            'already_posted' => (bool) $existing,
            'steps' => $steps,
            'trial_balance' => [
                'balanced' => $trialOk,
                'totals' => $trial['totals'],
            ],
            'profit_loss' => $pnl,
            'lines' => self::linesForApi($atelierId, $lines),
            'voucher' => $existing ? $existing->toApiArray() : null,
            'note' => 'بعد از بستن، ثبت و برگشت سند تا تاریخ بستن قفل می‌شود. اعتبار کیف پول مشتری بسته نمی‌شود؛ فقط هزینهٔ اعتبار مصرف‌شده (۶۱۳) به سود انباشته می‌رود.',
        ];
    }

    /**
     * @return array{voucher: ?AccountingVoucher, already_posted: bool, preview: array<string, mixed>}
     */
    public static function post(int $atelierId, string $mode, ?int $year, ?string $asOfJalali): array
    {
        if ($atelierId <= 0) {
            throw new RuntimeException('فروشگاه نامعتبر است.');
        }
        if (! AccountingLedger::ready()) {
            throw new RuntimeException('جدول سند حسابداری وجود ندارد. migration یا فایل SQL را اجرا کنید.');
        }

        $preview = self::preview($atelierId, $mode, $year, $asOfJalali);
        $resolved = self::resolveAsOf($mode, $year, $asOfJalali);

        $existing = AccountingVoucherService::findPosted(
            $atelierId,
            AccountingVoucher::SOURCE_YEAR_CLOSE,
            $resolved['source_id']
        );
        if ($existing) {
            return [
                'voucher' => $existing,
                'already_posted' => true,
                'preview' => $preview,
            ];
        }

        if (! $preview['can_close']) {
            $failed = [];
            foreach ($preview['steps'] as $step) {
                if (! $step['ok']) {
                    $failed[] = $step['title'].': '.$step['detail'];
                }
            }
            throw new RuntimeException($failed !== [] ? implode(' — ', $failed) : 'شرایط بستن دوره برقرار نیست.');
        }

        $pnl = $preview['profit_loss'];
        $lines = self::buildLines($atelierId, $pnl);
        if (count($lines) < 2) {
            throw new RuntimeException('سند بستن باید حداقل دو آرتیکل داشته باشد.');
        }

        $label = $resolved['mode'] === self::MODE_YEAR
            ? 'بستن سال مالی '.$resolved['year']
            : 'بستن میان‌دوره تا '.$resolved['as_of_jalali'];

        $voucher = AccountingVoucherService::post(
            $atelierId,
            $resolved['as_of_gregorian'],
            $label,
            AccountingVoucher::SOURCE_YEAR_CLOSE,
            $resolved['source_id'],
            $lines
        );

        $preview['already_posted'] = false;
        $preview['can_close'] = false;
        $preview['voucher'] = $voucher->toApiArray();
        $preview['closed_through'] = $resolved['as_of_jalali'];

        return [
            'voucher' => $voucher,
            'already_posted' => false,
            'preview' => $preview,
        ];
    }

    public static function reopenLatest(int $atelierId): AccountingVoucher
    {
        $latest = self::latestPosted($atelierId);
        if (! $latest) {
            throw new RuntimeException('سند بستن دوره‌ای برای برگشت نیست.');
        }

        return AccountingVoucherService::reverse($latest, 'بازگشایی بستن دوره');
    }

    /**
     * @return array{mode: string, year: int, as_of_jalali: string, as_of_gregorian: string, source_id: int}
     */
    public static function resolveAsOf(string $mode, ?int $year, ?string $asOfJalali): array
    {
        $mode = $mode === self::MODE_MID ? self::MODE_MID : self::MODE_YEAR;
        $today = Jalalian::fromCarbon(Carbon::now('Asia/Tehran'));

        if ($mode === self::MODE_YEAR) {
            $year = $year && $year >= 1300 && $year <= 1600 ? $year : (int) $today->getYear();
            $asOfJ = self::jalaliYearEndDate($year);
        } else {
            $asOfJ = $asOfJalali ? trim($asOfJalali) : $today->format('Y-m-d');
            $parsed = AccountingReportService::parseDate($asOfJ);
            if (! $parsed) {
                throw new RuntimeException('تاریخ بستن نامعتبر است.');
            }
            $j = Jalalian::fromCarbon(Carbon::parse($parsed));
            $asOfJ = $j->format('Y-m-d');
            $year = (int) $j->getYear();
        }

        $asOfG = AccountingReportService::parseDate($asOfJ);
        if (! $asOfG) {
            throw new RuntimeException('تاریخ بستن نامعتبر است.');
        }

        if (! preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $asOfJ, $m)) {
            throw new RuntimeException('تاریخ بستن نامعتبر است.');
        }
        $sourceId = ((int) $m[1] * 10000) + ((int) $m[2] * 100) + (int) $m[3];

        return [
            'mode' => $mode,
            'year' => $year,
            'as_of_jalali' => $asOfJ,
            'as_of_gregorian' => $asOfG,
            'source_id' => $sourceId,
        ];
    }

    public static function jalaliYearEndDate(int $year): string
    {
        try {
            return (new Jalalian($year, 12, 30))->format('Y-m-d');
        } catch (\Throwable $e) {
            return (new Jalalian($year, 12, 29))->format('Y-m-d');
        }
    }

    protected static function periodFromGregorian(int $atelierId, string $asOfG): ?string
    {
        $closed = self::closedThrough($atelierId);
        if (! $closed || $closed >= $asOfG) {
            return null;
        }

        return Carbon::parse($closed)->addDay()->toDateString();
    }

    /**
     * @param  array<string, mixed>  $pnl
     * @return array<int, array<string, mixed>>
     */
    protected static function buildLines(int $atelierId, array $pnl): array
    {
        $revenueId = AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_REVENUE);
        $otherId = AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_OTHER_INCOME);
        $discountId = AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_DISCOUNT);
        $cogsId = AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_COGS);
        $opexId = AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_EXPENSE);
        $payrollId = AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_PAYROLL);
        $loyaltyId = AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_LOYALTY);
        $retainedId = AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_RETAINED);

        $lines = [];
        $toRetained = 0.0;

        $toRetained += self::closeCreditNature($lines, $revenueId, (float) $pnl['sales'], 'بستن درآمد فروش');
        $toRetained += self::closeCreditNature($lines, $otherId, (float) $pnl['other_income'], 'بستن سایر درآمد');
        $toRetained += self::closeDebitNature($lines, $discountId, (float) $pnl['discounts'], 'بستن تخفیفات');
        $toRetained += self::closeDebitNature($lines, $cogsId, (float) $pnl['cogs'], 'بستن بهای تمام‌شده');
        $toRetained += self::closeDebitNature($lines, $opexId, (float) $pnl['operating_expense'], 'بستن هزینه جاری');
        $toRetained += self::closeDebitNature($lines, $payrollId, (float) $pnl['payroll'], 'بستن حقوق');
        $toRetained += self::closeDebitNature($lines, $loyaltyId, (float) $pnl['loyalty'], 'بستن اعتبار وفاداری');

        $toRetained = round($toRetained, 2);
        if ($toRetained > 0.009) {
            AccountingLedger::push($lines, $retainedId, 0, $toRetained, 'سود انباشته');
        } elseif ($toRetained < -0.009) {
            AccountingLedger::push($lines, $retainedId, abs($toRetained), 0, 'زیان انباشته');
        }

        return $lines;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected static function closeCreditNature(array &$lines, int $accountId, float $creditNet, string $description): float
    {
        $creditNet = round($creditNet, 2);
        if ($creditNet > 0.009) {
            AccountingLedger::push($lines, $accountId, $creditNet, 0, $description);

            return $creditNet;
        }
        if ($creditNet < -0.009) {
            AccountingLedger::push($lines, $accountId, 0, abs($creditNet), $description);

            return $creditNet;
        }

        return 0.0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected static function closeDebitNature(array &$lines, int $accountId, float $debitNet, string $description): float
    {
        $debitNet = round($debitNet, 2);
        if ($debitNet > 0.009) {
            AccountingLedger::push($lines, $accountId, 0, $debitNet, $description);

            return -$debitNet;
        }
        if ($debitNet < -0.009) {
            AccountingLedger::push($lines, $accountId, abs($debitNet), 0, $description);

            return abs($debitNet);
        }

        return 0.0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    protected static function linesForApi(int $atelierId, array $lines): array
    {
        $ids = [];
        foreach ($lines as $line) {
            $ids[] = (int) $line['account_id'];
        }
        $accounts = \App\Models\AccountingAccount::query()
            ->forAtelier($atelierId)
            ->whereIn('id', $ids ?: [0])
            ->get()
            ->keyBy('id');

        $out = [];
        foreach ($lines as $line) {
            $account = $accounts->get((int) $line['account_id']);
            $out[] = [
                'account_id' => (int) $line['account_id'],
                'account_code' => $account ? (string) $account->code : '',
                'account_name' => $account ? (string) $account->name : '',
                'debit' => round((float) $line['debit'], 2),
                'credit' => round((float) $line['credit'], 2),
                'description' => $line['description'] ?? '',
            ];
        }

        return $out;
    }

    /**
     * @return array{key: string, title: string, ok: bool, detail: string, hint: ?string}
     */
    protected static function step(string $key, string $title, bool $ok, string $detail, ?string $hint = null): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'ok' => $ok,
            'detail' => $detail,
            'hint' => $hint,
        ];
    }
}
