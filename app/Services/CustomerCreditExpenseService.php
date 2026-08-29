<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Purchase;
use App\Models\PurchaseItemReturn;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * ثبت اعطای اعتبار مشتری به‌صورت هزینه، بدون اثر روی موجودی حساب.
 * برگشت خرید در جمع سود/هزینه دوباره شمرده نمی‌شود (فروش از قبل کم شده).
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
     * هزینهٔ اعتبار وفاداری همان خرید (یک ردیف به ازای هر فاکتور).
     */
    public static function recordLoyaltyForPurchase(Purchase $purchase, ?string $userName = null): ?Expense
    {
        if (! self::supports()) {
            return null;
        }

        $amount = round((float) $purchase->credit_earned, 2);
        $atelierId = (int) $purchase->atelier_id;
        $phone = (string) ($purchase->phone ?? '');
        if ($atelierId <= 0 || $amount < 0.01 || $phone === '') {
            return null;
        }

        return self::upsert(
            $atelierId,
            $amount,
            self::titleLoyalty((int) $purchase->id, $phone),
            self::SOURCE_LOYALTY,
            (int) $purchase->id,
            $userName
        );
    }

    /**
     * کم کردن هزینهٔ وفاداری وقتی اعتبار کسب‌شده با برگشت یا لغو پس گرفته می‌شود.
     */
    public static function reduceLoyaltyForPurchase(Purchase $purchase, float $reverseAmount, ?string $userName = null): void
    {
        if (! self::supports() || $reverseAmount < 0.01) {
            return;
        }

        $expense = self::find((int) $purchase->atelier_id, self::SOURCE_LOYALTY, (int) $purchase->id);
        if (! $expense) {
            return;
        }

        $newAmount = round((float) $expense->amount - $reverseAmount, 2);
        if ($newAmount < 0.01) {
            $expense->delete();

            return;
        }

        $expense->amount = $newAmount;
        if ($userName) {
            $expense->user_name = $userName;
        }
        $expense->save();
    }

    public static function removeLoyaltyForPurchase(int $atelierId, int $purchaseId): void
    {
        if (! self::supports()) {
            return;
        }

        $expense = self::find($atelierId, self::SOURCE_LOYALTY, $purchaseId);
        if ($expense) {
            $expense->delete();
        }
    }

    /**
     * برگشت خرید: خالص اعتبار اضافه‌شده به کیف پول.
     * بخش وفاداری همان فاکتور از هزینهٔ قبلی کم می‌شود تا دو بار نیاید.
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

        self::reduceLoyaltyForPurchase($purchase, $creditEarnedReversed, $userName);

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
     * برگشت خرید در فروش خالص از قبل کم شده؛ این ردیف‌ها فقط برای پیگیری در لیست هزینه‌اند.
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
                ->orWhere('credit_source', '!=', self::SOURCE_RETURN);
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

        return Expense::create($payload);
    }

    protected static function titleLoyalty(int $purchaseId, string $phone): string
    {
        return 'اعتبار مشتری — وفاداری خرید #'.$purchaseId.' — '.$phone;
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
