<?php

namespace App\Services;

use App\Models\AccountingAccount;
use App\Models\AccountingLine;
use App\Models\AccountingVoucher;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class AccountingVoucherService
{
    public const BALANCE_TOLERANCE = 0.01;

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function post(
        int $atelierId,
        $date,
        ?string $description,
        string $sourceType,
        int $sourceId,
        array $lines,
        ?int $createdBy = null
    ): AccountingVoucher {
        self::assertReady();
        if ($atelierId <= 0) {
            throw new RuntimeException('فروشگاه نامعتبر است.');
        }

        $sourceType = trim($sourceType);
        if ($sourceType === '' || $sourceId <= 0) {
            throw new RuntimeException('کلید رویداد سند نامعتبر است.');
        }

        $normalized = self::normalizeLines($atelierId, $lines);
        $dateString = self::normalizeDate($date);
        $createdBy = $createdBy ?? (Auth::id() ? (int) Auth::id() : null);

        return DB::transaction(function () use (
            $atelierId,
            $dateString,
            $description,
            $sourceType,
            $sourceId,
            $normalized,
            $createdBy
        ) {
            self::lockAtelier($atelierId);

            $existing = self::findPosted($atelierId, $sourceType, $sourceId);
            if ($existing) {
                return $existing;
            }

            $voucher = new AccountingVoucher();
            $voucher->atelier_id = $atelierId;
            $voucher->number = self::nextNumber($atelierId);
            $voucher->date = $dateString;
            $voucher->description = $description;
            $voucher->source_type = $sourceType;
            $voucher->source_id = $sourceId;
            $voucher->status = AccountingVoucher::STATUS_POSTED;
            $voucher->reverses_voucher_id = null;
            $voucher->created_by = $createdBy;
            $voucher->active_source_key = AccountingVoucher::activeSourceKey($sourceType, $sourceId);
            $voucher->save();

            self::insertLines($voucher, $normalized);

            return $voucher->load(['lines.account']);
        });
    }

    public static function reverse(AccountingVoucher $voucher, ?string $description = null): AccountingVoucher
    {
        self::assertReady();

        return DB::transaction(function () use ($voucher, $description) {
            self::lockAtelier((int) $voucher->atelier_id);

            $locked = AccountingVoucher::query()
                ->where('id', $voucher->id)
                ->lockForUpdate()
                ->first();
            if (! $locked) {
                throw new RuntimeException('سند یافت نشد.');
            }
            if ($locked->status !== AccountingVoucher::STATUS_POSTED || $locked->reverses_voucher_id) {
                throw new RuntimeException('فقط سند ثبت‌شدهٔ غیربرگشتی را می‌توان برگشت زد.');
            }

            $already = AccountingVoucher::query()
                ->forAtelier((int) $locked->atelier_id)
                ->where('reverses_voucher_id', $locked->id)
                ->first();
            if ($already) {
                return $already->load(['lines.account']);
            }

            $locked->loadMissing('lines');
            $reversedLines = [];
            foreach ($locked->lines as $line) {
                $reversedLines[] = [
                    'account_id' => (int) $line->account_id,
                    'debit' => (float) $line->credit,
                    'credit' => (float) $line->debit,
                    'description' => $line->description,
                ];
            }

            $storno = new AccountingVoucher();
            $storno->atelier_id = (int) $locked->atelier_id;
            $storno->number = self::nextNumber((int) $locked->atelier_id);
            $storno->date = Carbon::now('Asia/Tehran')->toDateString();
            $storno->description = $description ?: ('برگشت سند '.$locked->number);
            $storno->source_type = $locked->source_type;
            $storno->source_id = (int) $locked->source_id;
            $storno->status = AccountingVoucher::STATUS_POSTED;
            $storno->reverses_voucher_id = $locked->id;
            $storno->created_by = Auth::id() ? (int) Auth::id() : $locked->created_by;
            $storno->active_source_key = null;
            $storno->save();

            self::insertLines($storno, self::normalizeLines((int) $locked->atelier_id, $reversedLines));

            $locked->status = AccountingVoucher::STATUS_REVERSED;
            $locked->active_source_key = null;
            $locked->save();

            return $storno->load(['lines.account']);
        });
    }

    public static function findPosted(int $atelierId, string $sourceType, int $sourceId): ?AccountingVoucher
    {
        if (! AccountingVoucher::tablesReady()) {
            return null;
        }

        return AccountingVoucher::query()
            ->forAtelier($atelierId)
            ->posted()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->with(['lines.account'])
            ->first();
    }

    public static function reversePostedIfAny(int $atelierId, string $sourceType, int $sourceId): ?AccountingVoucher
    {
        if (! AccountingVoucher::tablesReady()) {
            return null;
        }

        $existing = self::findPosted($atelierId, $sourceType, $sourceId);
        if (! $existing) {
            return null;
        }

        return self::reverse($existing);
    }

    /**
     * سناریوی ۲-۴ نقشه راه: سند متوازن، تکرار، نامتوازن، برگشت.
     *
     * @return array<string, mixed>
     */
    public static function selfTest(int $atelierId): array
    {
        self::assertReady();
        ChartOfAccountsSeeder::ensureForAtelier($atelierId);

        $till = AccountingAccount::query()->forAtelier($atelierId)->where('code', ChartOfAccountsSeeder::CODE_TILL)->first();
        $revenue = AccountingAccount::query()->forAtelier($atelierId)->where('code', '411')->first();
        if (! $till || ! $revenue) {
            throw new RuntimeException('حساب‌های بذر (۱۱۱۰۱ و ۴۱۱) یافت نشد. مرحله ۱ را اعمال کنید.');
        }

        $lines = [
            ['account_id' => $till->id, 'debit' => 100000, 'credit' => 0, 'description' => 'صندوق فروش'],
            ['account_id' => $revenue->id, 'debit' => 0, 'credit' => 100000, 'description' => 'درآمد فروش'],
        ];

        $first = self::post(
            $atelierId,
            Carbon::now('Asia/Tehran')->toDateString(),
            'تست تراز',
            AccountingVoucher::SOURCE_MANUAL,
            1,
            $lines
        );
        $second = self::post(
            $atelierId,
            Carbon::now('Asia/Tehran')->toDateString(),
            'تست تراز',
            AccountingVoucher::SOURCE_MANUAL,
            1,
            $lines
        );

        $unbalancedRejected = false;
        $unbalancedMessage = null;
        try {
            self::post(
                $atelierId,
                Carbon::now('Asia/Tehran')->toDateString(),
                'تست نامتوازن',
                AccountingVoucher::SOURCE_MANUAL,
                999999002,
                [
                    ['account_id' => $till->id, 'debit' => 100000, 'credit' => 0],
                    ['account_id' => $revenue->id, 'debit' => 0, 'credit' => 99999],
                ]
            );
        } catch (RuntimeException $e) {
            $unbalancedRejected = true;
            $unbalancedMessage = $e->getMessage();
        }

        $reversal = null;
        if ($first->fresh()->isPosted()) {
            $reversal = self::reverse($first);
        } else {
            $reversal = AccountingVoucher::query()
                ->forAtelier($atelierId)
                ->where('reverses_voucher_id', $first->id)
                ->with(['lines.account'])
                ->first();
        }

        $first = $first->fresh()->load(['lines.account']);

        return [
            'balanced_saved' => $first->id > 0 && abs((float) $first->toApiArray()['debit_total'] - 100000) < 0.01,
            'idempotent' => (int) $first->id === (int) $second->id,
            'unbalanced_rejected' => $unbalancedRejected,
            'unbalanced_message' => $unbalancedMessage,
            'reversed' => $reversal
                && $first->status === AccountingVoucher::STATUS_REVERSED
                && (int) $reversal->reverses_voucher_id === (int) $first->id,
            'numbers_sequential' => $reversal && (int) $reversal->number > (int) $first->number,
            'voucher' => $first->toApiArray(),
            'reversal' => $reversal ? $reversal->toApiArray() : null,
        ];
    }

    protected static function assertReady(): void
    {
        if (! AccountingVoucher::tablesReady()) {
            throw new RuntimeException('جدول سند حسابداری وجود ندارد. migration یا فایل SQL را اجرا کنید.');
        }
        if (! AccountingAccount::tableReady()) {
            throw new RuntimeException('جدول حسابداری وجود ندارد. ابتدا مرحله ۱ را اعمال کنید.');
        }
    }

    protected static function lockAtelier(int $atelierId): void
    {
        if (Schema::hasTable('ateliers')) {
            DB::table('ateliers')->where('id', $atelierId)->lockForUpdate()->first();
        }
    }

    protected static function nextNumber(int $atelierId): int
    {
        $max = (int) AccountingVoucher::query()->forAtelier($atelierId)->max('number');

        return $max + 1;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array{account_id: int, debit: float, credit: float, description: ?string}>
     */
    protected static function normalizeLines(int $atelierId, array $lines): array
    {
        if (count($lines) < 2) {
            throw new RuntimeException('سند باید حداقل دو آرتیکل داشته باشد.');
        }

        $normalized = [];
        $debitTotal = 0.0;
        $creditTotal = 0.0;
        $accountIds = [];

        foreach ($lines as $index => $line) {
            $accountId = isset($line['account_id']) ? (int) $line['account_id'] : 0;
            if ($accountId <= 0 && ! empty($line['account_code'])) {
                $accountId = (int) AccountingAccount::query()
                    ->forAtelier($atelierId)
                    ->where('code', (string) $line['account_code'])
                    ->value('id');
            }
            if ($accountId <= 0) {
                throw new RuntimeException('آرتیکل '.($index + 1).' حساب ندارد.');
            }

            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);
            if ($debit < 0 || $credit < 0) {
                throw new RuntimeException('مبلغ آرتیکل نمی‌تواند منفی باشد.');
            }
            if (($debit > 0 && $credit > 0) || ($debit <= 0 && $credit <= 0)) {
                throw new RuntimeException('هر آرتیکل باید دقیقاً بدهکار یا بستانکار باشد.');
            }

            $accountIds[] = $accountId;
            $debitTotal += $debit;
            $creditTotal += $credit;
            $normalized[] = [
                'account_id' => $accountId,
                'debit' => $debit,
                'credit' => $credit,
                'description' => isset($line['description']) ? (string) $line['description'] : null,
            ];
        }

        if (abs($debitTotal - $creditTotal) > self::BALANCE_TOLERANCE) {
            throw new RuntimeException('سند نامتوازن است. جمع بدهکار و بستانکار برابر نیست.');
        }

        $owned = AccountingAccount::query()
            ->forAtelier($atelierId)
            ->whereIn('id', array_unique($accountIds))
            ->pluck('id')
            ->all();
        if (count($owned) !== count(array_unique($accountIds))) {
            throw new RuntimeException('یکی از حساب‌های سند متعلق به این فروشگاه نیست.');
        }

        return $normalized;
    }

    /**
     * @param  array<int, array{account_id: int, debit: float, credit: float, description: ?string}>  $lines
     */
    protected static function insertLines(AccountingVoucher $voucher, array $lines): void
    {
        $now = now();
        foreach ($lines as $i => $line) {
            AccountingLine::create([
                'voucher_id' => $voucher->id,
                'account_id' => $line['account_id'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'description' => $line['description'],
                'sort_order' => $i + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    protected static function normalizeDate($date): string
    {
        if ($date instanceof Carbon) {
            return $date->toDateString();
        }
        $parsed = Carbon::parse((string) $date, 'Asia/Tehran');

        return $parsed->toDateString();
    }
}
