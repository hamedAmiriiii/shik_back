<?php

namespace App\Services;

use App\Models\Cheque;
use App\Models\Expense;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\PurchaseItemReturn;
use App\Models\PurchasedProduct;
use App\Models\ReturnedProduct;
use App\Models\UserShiksho;
use Illuminate\Support\Facades\Schema;
use App\Models\PurchaseStockConsumption;
use InvalidArgumentException;

class PurchaseReplaceService
{
    /**
     * آیا این فاکتور را می‌توان با سبد جدید جایگزین کرد.
     */
    public static function assertCanReplace(Purchase $purchase): void
    {
        if ($purchase->cart_id) {
            throw new InvalidArgumentException('سفارش اینترنتی از این مسیر ویرایش نمی‌شود.');
        }

        $purchase->loadMissing(['installments', 'cheque', 'purchasedProducts']);

        if ($purchase->isInstallment()) {
            $extraPaid = $purchase->installments
                ->where('is_paid', true)
                ->filter(function ($row) {
                    return (int) $row->installment_number > 1;
                })
                ->count();
            if ($extraPaid > 0) {
                throw new InvalidArgumentException(
                    'این فروش اقساطی قسط پرداخت‌شده دارد و قابل جایگزینی نیست. از برگشت استفاده کنید.'
                );
            }
        }

        if ($purchase->isCheque() && $purchase->isChequeSettled()) {
            throw new InvalidArgumentException('چک این فروش وصول شده و فاکتور قابل جایگزینی نیست.');
        }

        if ($purchase->isDebt() && $purchase->isDebtSettled()) {
            throw new InvalidArgumentException('بدهی این فروش تسویه شده و فاکتور قابل جایگزینی نیست.');
        }
    }

    /**
     * خالی کردن محتوای فاکتور: موجودی باقی‌مانده، برگشت‌ها، اعتبار، سند حسابداری.
     * ردیف Purchase حفظ می‌شود تا با اقلام جدید جایگزین شود.
     */
    public static function voidContents(Purchase $purchase): void
    {
        $purchase->load([
            'purchasedProducts.product',
            'purchasedProducts.producedGood',
            'purchasedProducts.rawMaterial',
            'purchasedProducts.stockConsumptions',
            'installments',
            'cheque',
        ]);

        $returns = collect();
        if (Schema::hasTable('purchase_item_returns')) {
            $returns = PurchaseItemReturn::query()
                ->where('purchase_id', $purchase->id)
                ->get();
        }

        $posSale = app(ShopPosSaleService::class);
        foreach ($purchase->purchasedProducts as $line) {
            $qty = (float) $line->quantity;
            if ($qty <= 0) {
                continue;
            }
            if ($line->product_id && $line->product) {
                $line->product->increment('quantity', $qty);
            } else {
                $posSale->restoreStock($line, $qty);
            }
        }

        self::restoreCustomerIncludingReturns($purchase, $returns);

        $atelierId = (int) $purchase->atelier_id;
        if ($atelierId > 0) {
            foreach ($returns as $log) {
                self::removeReturnExpense($atelierId, (int) $log->id);
            }
            CustomerCreditExpenseService::removeCreditUsedForPurchase($atelierId, (int) $purchase->id);
        }

        AccountingSalePoster::reversePurchase($purchase);

        if ($purchase->cheque && $purchase->cheque->status === Cheque::STATUS_PENDING) {
            $purchase->cheque->update(['purchase_id' => null]);
        }

        if (Schema::hasTable('returned_products') && Schema::hasColumn('returned_products', 'purchase_id')) {
            ReturnedProduct::query()->where('purchase_id', $purchase->id)->delete();
        }

        foreach ($returns as $log) {
            $log->delete();
        }

        $lineIds = $purchase->purchasedProducts->pluck('id')->filter()->all();
        if ($lineIds !== [] && Schema::hasTable('purchase_stock_consumptions')) {
            PurchaseStockConsumption::query()->whereIn('purchased_product_id', $lineIds)->delete();
        }

        Installment::query()->where('purchase_id', $purchase->id)->delete();
        PurchasedProduct::query()->where('purchase_id', $purchase->id)->delete();

        $purchase->cheque_id = null;
        $purchase->discount_amount = 0;
        $purchase->credit_used = 0;
        $purchase->credit_earned = 0;
        $purchase->card_amount = 0;
        $purchase->cash_amount = 0;
        $purchase->payment_type = 'cash';
        $purchase->installment_count = null;
        $purchase->installment_amount = null;
        $purchase->is_debt_settled = false;
        $purchase->debt_settled_at = null;
        $purchase->debt_settled_card_amount = 0;
        $purchase->debt_settled_cash_amount = 0;
        $purchase->debt_settlement_note = null;
        $purchase->total_amount = 0;
        $purchase->save();
        $purchase->unsetRelation('purchasedProducts');
        $purchase->unsetRelation('installments');
        $purchase->unsetRelation('cheque');
        $purchase->unsetRelation('itemReturns');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PurchaseItemReturn>  $returns
     */
    protected static function restoreCustomerIncludingReturns(Purchase $purchase, $returns): void
    {
        if (! $purchase->phone) {
            return;
        }

        $query = UserShiksho::where('phone', $purchase->phone);
        if ($purchase->atelier_id !== null) {
            $query->where('atelier_id', $purchase->atelier_id);
        }
        $customer = $query->lockForUpdate()->first();
        if (! $customer) {
            return;
        }

        $remainingSales = $purchase->remainingLineSalesTotal();
        $returnedSales = round((float) $returns->sum('return_sale_total'), 2);
        $originalSales = round($remainingSales + $returnedSales, 2);
        $earnedRemaining = round((float) $purchase->credit_earned, 2);
        $usedRemaining = round((float) $purchase->credit_used, 2);
        $earnedReversed = round((float) $returns->sum('credit_earned_reversed'), 2);
        $refunded = round((float) $returns->sum('credit_used_refund'), 2);
        $earnedOriginal = round($earnedRemaining + $earnedReversed, 2);
        $usedOriginal = $usedRemaining;
        if ($remainingSales > 0.01 && $originalSales > $remainingSales + 0.01 && $usedRemaining >= 0.01) {
            $usedOriginal = round($usedRemaining * $originalSales / $remainingSales, 2);
        }

        $delta = round($usedOriginal - $earnedOriginal - $refunded + $earnedReversed, 2);
        if (abs($delta) >= 0.01) {
            $customer->credit = max(0, round((float) $customer->credit + $delta, 2));
        }

        $unpaidReserve = round((float) $purchase->installments
            ->where('is_paid', false)
            ->sum('amount'), 2);
        if ($unpaidReserve >= 0.01) {
            $customer->installment_credit = round((float) $customer->installment_credit + $unpaidReserve, 2);
        }
        $customer->save();
    }

    protected static function removeReturnExpense(int $atelierId, int $returnId): void
    {
        if (! CustomerCreditExpenseService::supports() || $returnId <= 0) {
            return;
        }

        $expense = Expense::query()
            ->where('atelier_id', $atelierId)
            ->where('credit_source', CustomerCreditExpenseService::SOURCE_RETURN)
            ->where('credit_source_id', $returnId)
            ->first();
        if (! $expense) {
            return;
        }

        AccountingDocumentPoster::reverseExpense($expense);
        $expense->delete();
    }
}
