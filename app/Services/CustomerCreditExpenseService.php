<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Purchase;
use App\Models\PurchaseItemReturn;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * ثبت مصرف اعتبار و اعطای دستی به‌صورت هزینه، بدون اثر روی موجودی حساب.
 * اعتبار وفاداری تا مصرف فقط عدد است؛ هزینه وقتی ثبت می‌شود که از کیف پول کم شود.
 * برگشت خرید و مصرف اعتبار وفاداری در جمع سود/هزینه دوباره شمرده نمی‌شود
 * (فروش از قبل کم شده؛ اعتبار در سود فروش با credit_used آمده).
 */
class CustomerCreditExpenseService
{
    public const SOURCE_LOYALTY = 'loyalty_purchase';

    public const SOURCE_RETURN = 'purchase_return';

    public const SOURCE_MANUAL = 'manual';

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

        $amount = round((float) $purchase->credit_used, 2);
        $atelierId = (int) $purchase->atelier_id;
        $phone = (string) ($purchase->phone ?? '');
        if ($atelierId <= 0) {
            return null;
        }

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

    public static function recordManualGrant(
        int $atelierId,
        string $phone,
        float $amount,
        int $grantId,
        ?string $userName = null
    ): ?Expense {
        if (! self::supports() || $atelierId <= 0 || $amount < 0.01 || $grantId <= 0) {
            return null;
        }

        return self::upsert(
            $atelierId,
            round($amount, 2),
            self::titleManual($phone),
            self::SOURCE_MANUAL,
            $grantId,
            $userName
        );
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
                ->orWhereNotIn('credit_source', [self::SOURCE_RETURN, self::SOURCE_LOYALTY]);
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
        ?string $userName
    ): Expense {
        $existing = self::find($atelierId, $source, $sourceId);
        if ($existing) {
            $existing->amount = $amount;
            $existing->title = $title;
            if ($userName) {
                $existing->user_name = $userName;
            }
            $existing->save();
            AccountingDocumentPoster::syncExpense($existing);

            return $existing;
        }

        $payload = [
            'atelier_id' => $atelierId,
            'date' => Carbon::now()->format('Y-m-d'),
            'amount' => $amount,
            'title' => $title,
            'type' => 'جاری',
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
        AccountingDocumentPoster::postExpense($expense);

        return $expense;
    }

    protected static function titleLoyaltyUsed(int $purchaseId, string $phone): string
    {
        return 'اعتبار مشتری — مصرف اعتبار وفاداری #'.$purchaseId.' — '.$phone;
    }

    protected static function titleReturn(int $purchaseId, string $phone): string
    {
        return 'اعتبار مشتری — برگشت خرید #'.$purchaseId.' — '.$phone;
    }

    protected static function titleManual(string $phone): string
    {
        return 'اعتبار مشتری — افزایش دستی — '.$phone;
    }
}
