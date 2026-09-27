<?php

namespace App\Services;

use App\Models\AccountingVoucher;
use App\Models\Expense;
use App\Models\Purchase;
use App\Models\PurchaseItemReturn;
use RuntimeException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * هزینه فقط وقتی ساخته می‌شود که مشتری اعتبار را در خرید خرج کند.
 * افزایش دستی تا آن لحظه هزینه نیست. برگشت خرید هم هزینه نیست و از سود کم نمی‌شود.
 */
class CustomerCreditExpenseService
{
    public const SOURCE_LOYALTY = 'loyalty_purchase';

    public const SOURCE_RETURN = 'purchase_return';

    public const SOURCE_MANUAL = 'manual';

    public const TYPE_RETURN = 'برگشت';

    /** @var array<int, array<int, float>> */
    protected static array $fundedCache = [];

    public static function supports(): bool
    {
        return Schema::hasTable('expenses')
            && Schema::hasColumn('expenses', 'credit_source')
            && Schema::hasColumn('expenses', 'credit_source_id');
    }

    /**
     * هزینه وقتی مشتری اعتبار را روی فاکتور خرج می‌کند (نه وقتی فقط عدد اعتبار می‌گیرد).
     */
    public static function recordCreditUsed(Purchase $purchase, ?string $userName = null): ?Expense
    {
        if (! self::supports()) {
            return null;
        }

        $atelierId = (int) $purchase->atelier_id;
        $phone = (string) ($purchase->phone ?? '');
        if ($atelierId <= 0) {
            return null;
        }

        self::forgetFundedCache($atelierId);
        $amount = self::loyaltyPortion((float) $purchase->credit_used, self::returnFundedForPurchase($atelierId, (int) $purchase->id));
        if ($amount < 0.01 || $phone === '') {
            self::removeCreditUsedForPurchase($atelierId, (int) $purchase->id);

            return null;
        }

        return self::upsert(
            $atelierId,
            $amount,
            self::titleLoyaltyUsed((int) $purchase->id, $phone),
            self::SOURCE_LOYALTY,
            (int) $purchase->id,
            $userName
        );
    }

    public static function removeCreditUsedForPurchase(int $atelierId, int $purchaseId): void
    {
        if (! self::supports()) {
            return;
        }

        $expense = self::find($atelierId, self::SOURCE_LOYALTY, $purchaseId);
        if ($expense) {
            AccountingDocumentPoster::reverseExpense($expense);
            $expense->delete();
        }
    }

    public static function removeLoyaltyForPurchase(int $atelierId, int $purchaseId): void
    {
        self::removeCreditUsedForPurchase($atelierId, $purchaseId);
    }

    /**
     * برگشت خرید: خالص اعتبار اضافه‌شده به کیف پول.
     * هزینهٔ مصرف اعتبار همان فاکتور با credit_used باقی‌مانده همگام می‌شود.
     */
    public static function recordPurchaseReturn(
        Purchase $purchase,
        PurchaseItemReturn $log,
        float $creditRefunded,
        float $creditEarnedReversed,
        ?string $userName = null
    ): ?Expense {
        if (! self::supports()) {
            return null;
        }

        self::recordCreditUsed($purchase, $userName);

        $net = round(max(0, $creditRefunded - $creditEarnedReversed), 2);
        $atelierId = (int) $purchase->atelier_id;
        $phone = (string) ($purchase->phone ?? $log->phone ?? '');
        if ($atelierId <= 0 || $net < 0.01) {
            return null;
        }

        return self::upsert(
            $atelierId,
            $net,
            self::titleReturn((int) $purchase->id, $phone),
            self::SOURCE_RETURN,
            (int) $log->id,
            $userName
        );
    }

    public static function removePurchaseReturn(int $atelierId, int $returnId): void
    {
        if (! self::supports() || $returnId <= 0) {
            return;
        }

        $expense = self::find($atelierId, self::SOURCE_RETURN, $returnId);
        if (! $expense) {
            return;
        }

        AccountingDocumentPoster::reverseExpense($expense);
        $expense->delete();
    }

    public static function recordManualGrant(
        int $atelierId,
        string $phone,
        float $amount,
        int $grantId,
        ?string $userName = null
    ): ?Expense {
        return null;
    }

    /**
     * برگشت خرید و مصرف اعتبار وفاداری در جمع هزینه دوباره شمرده نمی‌شود.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function excludeFromTotals($query)
    {
        if (! self::supports()) {
            return $query;
        }

        return $query->where(function ($q) {
            $q->whereNull('credit_source')
                ->orWhereNotIn('credit_source', [self::SOURCE_RETURN, self::SOURCE_LOYALTY, self::SOURCE_MANUAL]);
        });
    }

    /**
     * اعطای اعتبار نقد از حساب کم نمی‌کند (نسیه / بدون shop_account).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function excludeAllCustomerCredit($query)
    {
        if (! self::supports()) {
            return $query;
        }

        return $query->whereNull('credit_source');
    }

    public static function sumForAtelier(int $atelierId, ?string $source = null): float
    {
        if (! self::supports()) {
            return 0.0;
        }

        $query = Expense::query()->where('atelier_id', $atelierId)->whereNotNull('credit_source');
        if ($source) {
            $query->where('credit_source', $source);
        }

        return (float) $query->sum('amount');
    }

    protected static function find(int $atelierId, string $source, int $sourceId): ?Expense
    {
        return Expense::query()
            ->where('atelier_id', $atelierId)
            ->where('credit_source', $source)
            ->where('credit_source_id', $sourceId)
            ->first();
    }

    protected static function upsert(
        int $atelierId,
        float $amount,
        string $title,
        string $source,
        int $sourceId,
        ?string $userName,
        ?string $date = null
    ): Expense {
        $existing = self::find($atelierId, $source, $sourceId);
        if ($existing) {
            $existing->amount = $amount;
            $existing->title = $title;
            if ($userName) {
                $existing->user_name = $userName;
            }
            if ($date) {
                $existing->date = $date;
            }
            $existing->save();
            if ($source !== self::SOURCE_LOYALTY && $source !== self::SOURCE_RETURN) {
                AccountingDocumentPoster::syncExpense($existing);
            }

            return $existing;
        }

        $payload = [
            'atelier_id' => $atelierId,
            'date' => $date ?: Carbon::now()->format('Y-m-d'),
            'amount' => $amount,
            'title' => $title,
            'type' => $source === self::SOURCE_RETURN ? self::returnExpenseType() : 'جاری',
            'user_name' => $userName ? trim($userName) : 'سیستم',
            'credit_source' => $source,
            'credit_source_id' => $sourceId,
        ];

        if (Schema::hasColumn('expenses', 'payment_method')) {
            $payload['payment_method'] = DocumentPaymentService::METHOD_CREDIT;
            $payload['payment_status'] = DocumentPaymentService::STATUS_UNPAID;
            $payload['shop_account_id'] = null;
        }

        $expense = Expense::create($payload);
        if ($source !== self::SOURCE_LOYALTY && $source !== self::SOURCE_RETURN) {
            AccountingDocumentPoster::postExpense($expense);
        }

        return $expense;
    }

    protected static function titleLoyaltyUsed(int $purchaseId, string $phone): string
    {
        return 'اعتبار مشتری — مصرف اعتبار در خرید #'.$purchaseId.' — '.$phone;
    }

    protected static function titleReturn(int $purchaseId, string $phone): string
    {
        return 'اعتبار مشتری — برگشت خرید #'.$purchaseId.' — '.$phone;
    }

    protected static function titleManual(string $phone): string
    {
        return 'اعتبار مشتری — افزایش دستی — '.$phone;
    }

    /**
     * بخشی از اعتبار مصرف‌شده که از برگشت خرید شارژ شده و نباید از سود کم شود.
     */
    public static function returnFundedForPurchase(int $atelierId, int $purchaseId): float
    {
        if ($atelierId <= 0 || $purchaseId <= 0) {
            return 0.0;
        }

        $map = self::fundedMap($atelierId);

        return (float) ($map[$purchaseId] ?? 0);
    }

    public static function loyaltyPortion(float $creditUsed, float $returnFunded): float
    {
        return round(max(0, $creditUsed - $returnFunded), 2);
    }

    public static function forgetFundedCache(?int $atelierId = null): void
    {
        if ($atelierId === null) {
            self::$fundedCache = [];

            return;
        }

        unset(self::$fundedCache[$atelierId]);
    }

    /**
     * ردیف هزینهٔ وفاداری را با سهم واقعی (غیر از اعتبار برگشت) هم‌خوان می‌کند.
     */
    public static function alignLoyaltyExpenses(int $atelierId): void
    {
        if (! self::supports() || $atelierId <= 0) {
            return;
        }

        self::forgetFundedCache($atelierId);
        self::dropManualGrantExpenses($atelierId);
        $map = self::fundedMap($atelierId);

        $purchases = DB::table('purchases')
            ->where('atelier_id', $atelierId)
            ->where('credit_used', '>=', 0.01)
            ->get(['id', 'phone', 'credit_used', 'created_at']);

        $activeIds = [];
        foreach ($purchases as $row) {
            $purchaseId = (int) $row->id;
            $portion = self::loyaltyPortion((float) $row->credit_used, (float) ($map[$purchaseId] ?? 0));
            if ($portion < 0.01) {
                continue;
            }
            $activeIds[] = $purchaseId;
            $phone = trim((string) ($row->phone ?? ''));
            if ($phone === '') {
                $phone = 'بدون شماره';
            }
            $date = null;
            if (! empty($row->created_at)) {
                $date = Carbon::parse($row->created_at)->timezone('Asia/Tehran')->toDateString();
            }
            self::upsert(
                $atelierId,
                $portion,
                self::titleLoyaltyUsed($purchaseId, $phone),
                self::SOURCE_LOYALTY,
                $purchaseId,
                null,
                $date
            );
        }

        $stale = Expense::query()
            ->where('atelier_id', $atelierId)
            ->where('credit_source', self::SOURCE_LOYALTY);
        if ($activeIds !== []) {
            $stale->whereNotIn('credit_source_id', $activeIds);
        }
        foreach ($stale->get() as $expense) {
            self::reverseAndDeleteIfOpen($expense);
        }

        if (self::returnExpenseType() !== self::TYPE_RETURN) {
            return;
        }

        Expense::query()
            ->where('atelier_id', $atelierId)
            ->where('credit_source', self::SOURCE_RETURN)
            ->where('type', '!=', self::TYPE_RETURN)
            ->update(['type' => self::TYPE_RETURN]);
    }

    /**
     * افزایش دستی تا خرج شدن در خرید هزینه نیست؛ ردیف‌های قبلی حذف می‌شوند.
     */
    public static function dropManualGrantExpenses(int $atelierId): void
    {
        if (! self::supports() || $atelierId <= 0) {
            return;
        }

        $expenses = Expense::query()
            ->where('atelier_id', $atelierId)
            ->where('credit_source', self::SOURCE_MANUAL)
            ->get();

        foreach ($expenses as $expense) {
            self::reverseAndDeleteIfOpen($expense);
        }
    }

    /**
     * سند دورهٔ بسته برگشت نمی‌خورد؛ ردیف هزینه هم می‌ماند تا لیست ۵۰۰ ندهد.
     */
    protected static function reverseAndDeleteIfOpen(Expense $expense): void
    {
        $voucher = AccountingVoucherService::findPosted(
            (int) $expense->atelier_id,
            AccountingVoucher::SOURCE_EXPENSE,
            (int) $expense->id
        );
        if ($voucher) {
            try {
                AccountingPeriodCloseService::assertReversible($voucher);
            } catch (RuntimeException $e) {
                return;
            }
        }

        try {
            AccountingDocumentPoster::reverseExpense($expense);
        } catch (RuntimeException $e) {
            return;
        }

        $expense->delete();
    }

    /**
     * @return array<int, float>
     */
    protected static function fundedMap(int $atelierId): array
    {
        if (isset(self::$fundedCache[$atelierId])) {
            return self::$fundedCache[$atelierId];
        }

        if (! Schema::hasTable('purchase_item_returns') || ! Schema::hasTable('purchases')) {
            return self::$fundedCache[$atelierId] = [];
        }

        $events = [];
        $returns = DB::table('purchase_item_returns')
            ->where('atelier_id', $atelierId)
            ->get(['created_at', 'credit_used_refund', 'credit_earned_reversed']);
        foreach ($returns as $row) {
            $net = round(max(0, (float) $row->credit_used_refund - (float) $row->credit_earned_reversed), 2);
            if ($net < 0.01) {
                continue;
            }
            $events[] = ['t' => (string) $row->created_at, 'kind' => 0, 'id' => 0, 'amount' => $net];
        }

        $uses = DB::table('purchases')
            ->where('atelier_id', $atelierId)
            ->where('credit_used', '>=', 0.01)
            ->get(['id', 'created_at', 'credit_used']);
        foreach ($uses as $row) {
            $events[] = [
                't' => (string) $row->created_at,
                'kind' => 1,
                'id' => (int) $row->id,
                'amount' => round((float) $row->credit_used, 2),
            ];
        }

        usort($events, function (array $a, array $b) {
            $cmp = strcmp($a['t'], $b['t']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return $a['kind'] <=> $b['kind'];
        });

        $pool = 0.0;
        $map = [];
        foreach ($events as $event) {
            if ($event['kind'] === 0) {
                $pool = round($pool + $event['amount'], 2);

                continue;
            }
            $take = round(min($pool, $event['amount']), 2);
            $pool = round(max(0, $pool - $take), 2);
            if ($take >= 0.01) {
                $map[$event['id']] = round(($map[$event['id']] ?? 0) + $take, 2);
            }
        }

        return self::$fundedCache[$atelierId] = $map;
    }

    protected static function returnExpenseType(): string
    {
        static $type = null;
        if ($type !== null) {
            return $type;
        }

        try {
            $column = DB::selectOne("SHOW COLUMNS FROM expenses WHERE Field = 'type'");
            $sqlType = strtolower((string) ($column->Type ?? ''));
            if ($sqlType !== '' && str_starts_with($sqlType, 'enum(') && ! str_contains($sqlType, 'برگشت')) {
                return $type = 'جاری';
            }
        } catch (\Throwable $e) {
            return $type = 'جاری';
        }

        return $type = self::TYPE_RETURN;
    }
}
