<?php

namespace App\Services;

use App\Models\AccountingVoucher;
use App\Models\Cheque;
use App\Models\DocumentPayment;
use App\Models\Purchase;
use App\Models\PurchaseDebtPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * برگشت عملیاتیِ اسنادی که از عملیات ساخته شده‌اند (تسویه نسیه، وصول چک، …) از روی خود سند حسابداری.
 * هر مورد هم سند را برگشت می‌زند و هم مدرک اصلی را به وضعیت قبل از تسویه برمی‌گرداند.
 */
class AccountingOperationReversalService
{
    /**
     * @return array<string, string> source_type => برچسب دکمه
     */
    public static function supportedSources(): array
    {
        return [
            AccountingVoucher::SOURCE_DOCUMENT_PAYMENT => 'برگشت تسویه',
            AccountingVoucher::SOURCE_DEBT_PAYMENT => 'برگشت پرداخت نسیه',
            AccountingVoucher::SOURCE_DEBT_SETTLE => 'برگشت تسویه نسیه',
            AccountingVoucher::SOURCE_CHEQUE_CLEAR => 'برگشت وصول چک',
        ];
    }

    public static function supports(AccountingVoucher $voucher): bool
    {
        return array_key_exists((string) $voucher->source_type, self::supportedSources());
    }

    /**
     * @return string پیام موفقیت
     */
    public static function reverse(AccountingVoucher $voucher): string
    {
        if (! self::supports($voucher)) {
            throw new RuntimeException('این سند از عملیات ساخته شده و از این مسیر قابل برگشت نیست.');
        }
        if ($voucher->status !== AccountingVoucher::STATUS_POSTED || $voucher->reverses_voucher_id) {
            throw new RuntimeException('فقط سند ثبت‌شدهٔ غیربرگشتی قابل برگشت است.');
        }

        $atelierId = (int) $voucher->atelier_id;
        $sourceId = (int) $voucher->source_id;

        switch ($voucher->source_type) {
            case AccountingVoucher::SOURCE_DOCUMENT_PAYMENT:
                $payment = DocumentPayment::query()
                    ->where('id', $sourceId)
                    ->where('atelier_id', $atelierId)
                    ->first();
                if (! $payment) {
                    throw new RuntimeException('پرداخت مربوط به این سند یافت نشد.');
                }
                DocumentPaymentService::unsettle($payment);

                return 'تسویه برگشت خورد و مبلغ دوباره نسیه شد.';

            case AccountingVoucher::SOURCE_DEBT_PAYMENT:
                if (! Schema::hasTable('purchase_debt_payments')) {
                    throw new RuntimeException('جدول پرداخت‌های نسیه وجود ندارد.');
                }
                $debtPayment = PurchaseDebtPayment::query()->where('id', $sourceId)->first();
                if (! $debtPayment || ! $debtPayment->purchase || (int) $debtPayment->purchase->atelier_id !== $atelierId) {
                    throw new RuntimeException('پرداخت نسیه مربوط به این سند یافت نشد.');
                }
                self::reverseDebtPayment($voucher, $debtPayment);

                return 'پرداخت نسیه برگشت خورد و مانده بدهی مشتری دوباره باز شد.';

            case AccountingVoucher::SOURCE_DEBT_SETTLE:
                $purchase = Purchase::query()->where('id', $sourceId)->where('atelier_id', $atelierId)->first();
                if (! $purchase) {
                    throw new RuntimeException('فروش مربوط به این سند یافت نشد.');
                }
                self::reverseDebtSettle($voucher, $purchase);

                return 'تسویه نسیه برگشت خورد و بدهی مشتری دوباره باز شد.';

            case AccountingVoucher::SOURCE_CHEQUE_CLEAR:
                $cheque = Cheque::query()->where('id', $sourceId)->where('atelier_id', $atelierId)->first();
                if (! $cheque) {
                    throw new RuntimeException('چک مربوط به این سند یافت نشد.');
                }
                $reverted = $cheque->unclear();

                return $reverted->type === Cheque::TYPE_RECEIVED
                    ? 'وصول چک دریافتی برگشت خورد و چک به «در انتظار وصول» برگشت.'
                    : 'وصول چک صادره برگشت خورد و چک به «در انتظار وصول» برگشت.';
        }

        throw new RuntimeException('این سند قابل برگشت نیست.');
    }

    protected static function reverseDebtPayment(AccountingVoucher $voucher, PurchaseDebtPayment $payment): void
    {
        DB::transaction(function () use ($voucher, $payment) {
            $locked = PurchaseDebtPayment::query()->where('id', $payment->id)->lockForUpdate()->first();
            if (! $locked) {
                throw new RuntimeException('این پرداخت قبلاً برگشت خورده است.');
            }
            $purchase = Purchase::query()->where('id', $locked->purchase_id)->lockForUpdate()->first();
            if (! $purchase) {
                throw new RuntimeException('فروش مربوط به این پرداخت یافت نشد.');
            }

            // اگر دورهٔ مالی بسته باشد همین‌جا خطا می‌دهد و چیزی تغییر نمی‌کند.
            AccountingVoucherService::reverse($voucher, 'برگشت پرداخت نسیه فروش #'.(int) $purchase->id);

            $locked->delete();
            self::refreshDebtTotals($purchase);
        });
    }

    protected static function reverseDebtSettle(AccountingVoucher $voucher, Purchase $purchase): void
    {
        DB::transaction(function () use ($voucher, $purchase) {
            $locked = Purchase::query()->where('id', $purchase->id)->lockForUpdate()->first();
            if (! $locked || ! $locked->isDebt()) {
                throw new RuntimeException('این فروش نسیه نیست.');
            }
            if (! $locked->is_debt_settled) {
                throw new RuntimeException('این فروش در حال حاضر تسویه‌شده نیست.');
            }

            AccountingVoucherService::reverse($voucher, 'برگشت تسویه نسیه فروش #'.(int) $locked->id);

            // تسویهٔ یک‌جا (بدون ردیف پرداخت): مبالغ تسویه صفر می‌شود.
            $payload = [
                'is_debt_settled' => false,
                'debt_settled_at' => null,
                'debt_settled_card_amount' => 0,
                'debt_settled_cash_amount' => 0,
            ];
            $locked->update($payload);
            self::refreshDebtTotals($locked->fresh());
        });
    }

    /**
     * مجموع وصولی‌ها و وضعیت تسویه را از روی ردیف‌های باقی‌ماندهٔ پرداخت دوباره می‌سازد.
     */
    protected static function refreshDebtTotals(Purchase $purchase): void
    {
        $card = 0.0;
        $cash = 0.0;
        if (Schema::hasTable('purchase_debt_payments')) {
            $rows = PurchaseDebtPayment::query()->where('purchase_id', $purchase->id)->get();
            $card = round((float) $rows->sum('card_amount'), 2);
            $cash = round((float) $rows->sum('cash_amount'), 2);
        }

        $purchase->unsetRelation('debtPayments');
        $purchase->update([
            'is_debt_settled' => false,
            'debt_settled_at' => null,
            'debt_settled_card_amount' => $card,
            'debt_settled_cash_amount' => $cash,
        ]);
    }
}
