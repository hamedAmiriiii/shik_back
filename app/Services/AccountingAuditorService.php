<?php

namespace App\Services;

use App\Models\AccountingAccount;
use App\Models\AccountingAuditLog;
use App\Models\AccountingVoucher;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Morilog\Jalali\Jalalian;
use RuntimeException;

/**
 * کار حسابرس روی اسناد، حتی در دوره‌های بسته.
 * - ویرایش = برگشت سند با همان تاریخ + سند جدید؛ تاریخچه در دفتر می‌ماند.
 * - هر تغییر در دورهٔ بسته یک «تکمیل بستن دوره» با تاریخ همان بستن می‌گیرد تا حساب‌های موقت آن دوره صفر بمانند
 *   و اثر سود/زیان به سود انباشته (۳۵) برود.
 * - تعدیلات سنواتی: سند در دورهٔ باز که به‌جای حساب‌های موقت مستقیم به ۳۵ می‌خورد.
 */
class AccountingAuditorService
{
    public const TEMP_KINDS = [
        AccountingAccount::KIND_REVENUE,
        AccountingAccount::KIND_COGS,
        AccountingAccount::KIND_EXPENSE,
    ];

    /** سندهایی که حسابرس مستقیم برگشت/اصلاح می‌کند؛ بقیه سیستمی‌اند و فقط سند اصلاحی می‌گیرند. */
    public const EDITABLE_SOURCES = [
        AccountingVoucher::SOURCE_MANUAL,
        AccountingVoucher::SOURCE_PRIOR_YEAR_ADJUST,
    ];

    public static function canEditClosedPeriods(?User $user): bool
    {
        return ShopStaffAccess::isAuditor($user);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{voucher: AccountingVoucher, close_adjust: ?AccountingVoucher}
     */
    public static function create(int $atelierId, ?int $userId, string $date, ?string $description, array $lines, ?string $reason): array
    {
        $closeDate = AccountingPeriodCloseService::closeDateCovering($atelierId, $date);
        self::assertReason($closeDate !== null, $reason);

        return DB::transaction(function () use ($atelierId, $userId, $date, $description, $lines, $reason, $closeDate) {
            AccountingVoucherService::lockAtelier($atelierId);

            $voucher = AccountingPeriodCloseService::withClosedPeriodOverride(fn () => AccountingVoucherService::post(
                $atelierId,
                $date,
                $description,
                AccountingVoucher::SOURCE_MANUAL,
                self::nextSourceId($atelierId, AccountingVoucher::SOURCE_MANUAL),
                $lines,
                $userId
            ));
            $adjust = $closeDate ? self::postCloseAdjust($atelierId, $closeDate, $voucher, $userId) : null;

            self::log($atelierId, $userId, AccountingAuditLog::ACTION_CREATE, $voucher, null, $closeDate, $reason, [
                'close_adjust_voucher_id' => $adjust ? (int) $adjust->id : null,
            ]);

            return ['voucher' => $voucher, 'close_adjust' => $adjust];
        }, 5);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{voucher: AccountingVoucher, storno: AccountingVoucher}
     */
    public static function correct(
        int $atelierId,
        ?int $userId,
        AccountingVoucher $original,
        ?string $date,
        ?string $description,
        array $lines,
        ?string $reason
    ): array {
        self::assertEditable($atelierId, $original);
        if ($original->source_type !== AccountingVoucher::SOURCE_MANUAL) {
            throw new RuntimeException('فقط سند دستی را می‌توان اصلاح کرد. تعدیلات سنواتی را برگشت بزنید و دوباره ثبت کنید.');
        }

        $originalDate = Carbon::parse($original->date)->toDateString();
        $newDate = $date ?: $originalDate;
        $originalClose = AccountingPeriodCloseService::closeDateCovering($atelierId, $originalDate);
        $newClose = AccountingPeriodCloseService::closeDateCovering($atelierId, $newDate);
        self::assertReason($originalClose !== null || $newClose !== null, $reason);

        return DB::transaction(function () use (
            $atelierId,
            $userId,
            $original,
            $originalDate,
            $newDate,
            $originalClose,
            $newClose,
            $description,
            $lines,
            $reason
        ) {
            AccountingVoucherService::lockAtelier($atelierId);

            $storno = AccountingPeriodCloseService::withClosedPeriodOverride(fn () => AccountingVoucherService::reverse(
                $original,
                'اصلاح سند '.$original->number,
                $originalDate
            ));
            $stornoAdjust = $originalClose ? self::postCloseAdjust($atelierId, $originalClose, $storno, $userId) : null;

            $voucher = AccountingPeriodCloseService::withClosedPeriodOverride(fn () => AccountingVoucherService::post(
                $atelierId,
                $newDate,
                $description ?? $original->description,
                AccountingVoucher::SOURCE_MANUAL,
                self::nextSourceId($atelierId, AccountingVoucher::SOURCE_MANUAL),
                $lines,
                $userId
            ));
            $newAdjust = $newClose ? self::postCloseAdjust($atelierId, $newClose, $voucher, $userId) : null;

            self::log($atelierId, $userId, AccountingAuditLog::ACTION_CORRECT, $voucher, $original, $originalClose ?? $newClose, $reason, [
                'storno_voucher_id' => (int) $storno->id,
                'close_adjust_voucher_ids' => array_values(array_filter([
                    $stornoAdjust ? (int) $stornoAdjust->id : null,
                    $newAdjust ? (int) $newAdjust->id : null,
                ])),
            ]);

            return ['voucher' => $voucher, 'storno' => $storno];
        }, 5);
    }

    public static function reverse(int $atelierId, ?int $userId, AccountingVoucher $original, ?string $reason): AccountingVoucher
    {
        self::assertEditable($atelierId, $original);

        $originalDate = Carbon::parse($original->date)->toDateString();
        $closeDate = AccountingPeriodCloseService::closeDateCovering($atelierId, $originalDate);
        self::assertReason($closeDate !== null, $reason);

        return DB::transaction(function () use ($atelierId, $userId, $original, $originalDate, $closeDate, $reason) {
            AccountingVoucherService::lockAtelier($atelierId);

            $storno = AccountingPeriodCloseService::withClosedPeriodOverride(fn () => AccountingVoucherService::reverse(
                $original,
                'برگشت سند '.$original->number.' توسط حسابرس',
                $originalDate
            ));
            $adjust = $closeDate ? self::postCloseAdjust($atelierId, $closeDate, $storno, $userId) : null;

            self::log($atelierId, $userId, AccountingAuditLog::ACTION_REVERSE, $storno, $original, $closeDate, $reason, [
                'close_adjust_voucher_id' => $adjust ? (int) $adjust->id : null,
            ]);

            return $storno;
        }, 5);
    }

    /**
     * آرتیکل‌ها مثل سند دورهٔ قبل وارد می‌شوند؛ حساب‌های درآمد/بها/هزینه به ۳۵ برگردانده می‌شوند.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function priorYearAdjust(int $atelierId, ?int $userId, ?string $date, ?string $description, array $lines, ?string $reason): AccountingVoucher
    {
        $closed = AccountingPeriodCloseService::closedThrough($atelierId);
        if (! $closed) {
            throw new RuntimeException('هنوز دوره‌ای بسته نشده است؛ سند را مستقیم با تاریخ خودش ثبت کنید.');
        }

        $dateString = $date ?: Carbon::now('Asia/Tehran')->toDateString();
        if ($dateString <= $closed) {
            $jalali = Jalalian::fromCarbon(Carbon::parse($closed))->format('Y-m-d');
            throw new RuntimeException('تاریخ تعدیلات سنواتی باید بعد از آخرین بستن ('.$jalali.') باشد.');
        }
        self::assertReason(true, $reason);

        $mapped = self::mapTempToRetained($atelierId, $lines);

        return DB::transaction(function () use ($atelierId, $userId, $dateString, $description, $mapped, $reason) {
            AccountingVoucherService::lockAtelier($atelierId);

            $voucher = AccountingVoucherService::post(
                $atelierId,
                $dateString,
                $description ?: 'تعدیلات سنواتی',
                AccountingVoucher::SOURCE_PRIOR_YEAR_ADJUST,
                self::nextSourceId($atelierId, AccountingVoucher::SOURCE_PRIOR_YEAR_ADJUST),
                $mapped,
                $userId
            );

            self::log($atelierId, $userId, AccountingAuditLog::ACTION_PRIOR_YEAR_ADJUST, $voucher, null, null, $reason, []);

            return $voucher;
        }, 5);
    }

    /**
     * اثر حساب‌های موقت سند را با تاریخ بستن دوره به ۳۵ می‌بندد؛ اگر سند حساب موقت نداشت null.
     */
    protected static function postCloseAdjust(int $atelierId, string $closeDate, AccountingVoucher $voucher, ?int $userId): ?AccountingVoucher
    {
        $voucher->loadMissing('lines.account');

        $byAccount = [];
        foreach ($voucher->lines as $line) {
            if (! $line->account || ! in_array($line->account->kind, self::TEMP_KINDS, true)) {
                continue;
            }
            $id = (int) $line->account_id;
            $byAccount[$id] = ($byAccount[$id] ?? 0.0) + (float) $line->debit - (float) $line->credit;
        }

        $lines = [];
        $toRetained = 0.0;
        foreach ($byAccount as $accountId => $net) {
            $net = round($net, 2);
            if (abs($net) < 0.01) {
                continue;
            }
            AccountingLedger::push($lines, $accountId, $net < 0 ? abs($net) : 0, $net > 0 ? $net : 0, 'بستن اثر سند '.$voucher->number);
            $toRetained += $net;
        }
        if ($lines === []) {
            return null;
        }

        $retainedId = AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_RETAINED);
        $toRetained = round($toRetained, 2);
        AccountingLedger::push(
            $lines,
            $retainedId,
            $toRetained > 0 ? $toRetained : 0,
            $toRetained < 0 ? abs($toRetained) : 0,
            $toRetained > 0 ? 'زیان انباشته' : 'سود انباشته'
        );

        return AccountingPeriodCloseService::withClosedPeriodOverride(fn () => AccountingVoucherService::post(
            $atelierId,
            $closeDate,
            'تکمیل بستن دوره برای سند '.$voucher->number,
            AccountingVoucher::SOURCE_YEAR_CLOSE_ADJUST,
            (int) $voucher->id,
            $lines,
            $userId
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    protected static function mapTempToRetained(int $atelierId, array $lines): array
    {
        $ids = [];
        $codes = [];
        foreach ($lines as $line) {
            if (! empty($line['account_id'])) {
                $ids[] = (int) $line['account_id'];
            } elseif (! empty($line['account_code'])) {
                $codes[] = (string) $line['account_code'];
            }
        }

        $accounts = AccountingAccount::query()
            ->forAtelier($atelierId)
            ->where(function ($q) use ($ids, $codes) {
                $q->whereIn('id', $ids ?: [0])->orWhereIn('code', $codes ?: ['']);
            })
            ->get();
        $byId = $accounts->keyBy('id');
        $byCode = $accounts->keyBy('code');
        $retainedId = AccountingLedger::accountId($atelierId, ChartOfAccountsSeeder::CODE_RETAINED);

        $out = [];
        foreach ($lines as $line) {
            $account = ! empty($line['account_id'])
                ? $byId->get((int) $line['account_id'])
                : (! empty($line['account_code']) ? $byCode->get((string) $line['account_code']) : null);
            if ($account && in_array($account->kind, self::TEMP_KINDS, true)) {
                $description = trim((string) ($line['description'] ?? ''));
                $line['account_id'] = $retainedId;
                unset($line['account_code']);
                $line['description'] = 'تعدیل سنواتی '.$account->code.' '.$account->name.($description !== '' ? ' — '.$description : '');
            }
            $out[] = $line;
        }

        return $out;
    }

    protected static function assertEditable(int $atelierId, AccountingVoucher $voucher): void
    {
        if ((int) $voucher->atelier_id !== $atelierId) {
            throw new RuntimeException('سند یافت نشد.');
        }
        if (! $voucher->isPosted()) {
            throw new RuntimeException('فقط سند ثبت‌شدهٔ غیربرگشتی را می‌توان تغییر داد.');
        }
        if (! in_array($voucher->source_type, self::EDITABLE_SOURCES, true)) {
            throw new RuntimeException('این سند از عملیات سیستم ساخته شده است؛ برای اصلاح آن سند اصلاحی با همان تاریخ ثبت کنید.');
        }
    }

    protected static function assertReason(bool $required, ?string $reason): void
    {
        if ($required && mb_strlen(trim((string) $reason)) < 3) {
            throw new RuntimeException('برای تغییر در دورهٔ بسته یا تعدیلات سنواتی، دلیل را بنویسید.');
        }
    }

    protected static function nextSourceId(int $atelierId, string $sourceType): int
    {
        $max = (int) AccountingVoucher::query()
            ->forAtelier($atelierId)
            ->where('source_type', $sourceType)
            ->max('source_id');

        return max(1, $max + 1);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected static function log(
        int $atelierId,
        ?int $userId,
        string $action,
        ?AccountingVoucher $voucher,
        ?AccountingVoucher $related,
        ?string $closeDate,
        ?string $reason,
        array $payload
    ): void {
        if (! AccountingAuditLog::tableReady()) {
            throw new RuntimeException('جدول accounting_audit_logs وجود ندارد. migration یا SQL لاگ حسابرس را اجرا کنید.');
        }

        AccountingAuditLog::create([
            'atelier_id' => $atelierId,
            'user_id' => $userId,
            'action' => $action,
            'voucher_id' => $voucher ? (int) $voucher->id : null,
            'related_voucher_id' => $related ? (int) $related->id : null,
            'closed_through' => $closeDate,
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
            'payload' => $payload,
        ]);
    }
}
