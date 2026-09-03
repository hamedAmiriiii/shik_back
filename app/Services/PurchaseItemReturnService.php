<?php

namespace App\Services;

use App\Models\Cheque;
use App\Models\Purchase;
use App\Models\PurchaseItemReturn;
use App\Models\PurchasedProduct;
use App\Models\Product;
use App\Models\ReturnedProduct;
use App\Models\UserShiksho;
use App\Services\CustomerCreditExpenseService;
use App\Tools\PhoneTools;
use App\Tools\PriceTools;
use App\Tools\ProductQuantityTools;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurchaseItemReturnService
{
    /**
     * برگشت کامل همه اقلام باقی‌مانده فاکتور.
     *
     * @return array<string, mixed>
     */
    public static function processFullReturn(
        Purchase $purchase,
        ?string $phone = null,
        ?string $userName = null,
        ?string $notes = null
    ): array {
        return DB::transaction(function () use ($purchase, $phone, $userName, $notes) {
            $purchase->load('purchasedProducts');
            if ($purchase->purchasedProducts->isEmpty()) {
                throw new \InvalidArgumentException('این خرید اقلام باقی‌مانده برای برگشت ندارد.');
            }

            $items = $purchase->purchasedProducts->all();
            $returnedItems = [];
            $rows = [];
            $creditRefunded = 0.0;
            $creditEarnedReversed = 0.0;

            foreach ($items as $item) {
                $freshPurchase = Purchase::query()->with('purchasedProducts')->find($purchase->id);
                $freshItem = PurchasedProduct::query()->find($item->id);
                if (! $freshPurchase || ! $freshItem) {
                    continue;
                }
                $qty = (float) $freshItem->quantity;
                if ($qty <= 0) {
                    continue;
                }
                $result = self::processReturn(
                    $freshPurchase,
                    $freshItem,
                    $qty,
                    $userName,
                    $notes,
                    $phone,
                    false
                );
                $returnedItems[] = $result['returned_item'];
                $rows[] = $result['row'];
                $creditRefunded += (float) $result['returned_item']['credit_refunded'];
                $creditEarnedReversed += (float) $result['returned_item']['credit_earned_reversed'];
            }

            $purchase = Purchase::query()
                ->with(['purchasedProducts.product', 'purchasedProducts.producedGood', 'purchasedProducts.rawMaterial'])
                ->findOrFail($purchase->id);

            $customer = self::findUserShiksho($purchase);

            return [
                'full_return' => true,
                'returned_items' => $returnedItems,
                'rows' => $rows,
                'credit_refunded' => round($creditRefunded, 2),
                'credit_earned_reversed' => round($creditEarnedReversed, 2),
                'customer_credit' => $customer ? (float) $customer->credit : 0,
                'phone' => $purchase->phone,
                'purchase' => $purchase,
            ];
        });
    }

    /**
     * برگشت یک یا چند عدد از خط فاکتور.
     * سهم پرداخت‌نشده (نسیه/قسط/چک) بسته می‌شود؛ فقط سهم نقدی پرداخت‌شده به اعتبار می‌رود.
     *
     * @return array<string, mixed>
     */
    public static function processReturn(
        Purchase $purchase,
        PurchasedProduct $purchasedProduct,
        float $returnQuantity,
        ?string $userName = null,
        ?string $notes = null,
        ?string $phone = null,
        bool $useTransaction = true
    ): array {
        $run = function () use ($purchase, $purchasedProduct, $returnQuantity, $userName, $notes, $phone) {
            if ((int) $purchasedProduct->purchase_id !== (int) $purchase->id) {
                throw new \InvalidArgumentException('این محصول متعلق به این خرید نیست');
            }

            $purchasedProduct->load(['product', 'producedGood', 'rawMaterial']);

            $product = $purchasedProduct->product;
            $unitType = Product::UNIT_KG;
            if ($product instanceof Product) {
                $unitType = $product->unit_type ?? Product::UNIT_PIECE;
            } elseif (! $purchasedProduct->produced_good_id && ! $purchasedProduct->raw_material_id) {
                throw new \InvalidArgumentException('محصول یافت نشد');
            }
            $returnQuantity = ProductQuantityTools::normalize($returnQuantity, $unitType);
            $lineQty = ProductQuantityTools::normalize($purchasedProduct->quantity, $unitType);

            if ($error = ProductQuantityTools::validateReturnQuantity($returnQuantity, $lineQty, $unitType)) {
                throw new \InvalidArgumentException($error);
            }

            $unitSale = (float) $purchasedProduct->sale_price;
            $unitPurchase = (float) $purchasedProduct->purchase_price;
            $returnAmount = round($unitSale * $returnQuantity, 2);
            $returnPurchaseTotal = round($unitPurchase * $returnQuantity, 2);

            $purchase->load('purchasedProducts');
            $lineTotalBeforeReturn = (float) $purchase->purchasedProducts->sum(function ($pp) {
                return (float) $pp->sale_price * (float) $pp->quantity;
            });
            $ratio = $lineTotalBeforeReturn > 0
                ? min(1, $returnAmount / $lineTotalBeforeReturn)
                : 1;

            $atelierId = (int) ($purchase->atelier_id
                ?? optional($product)->atelier_id
                ?? optional($purchasedProduct->producedGood)->atelier_id
                ?? optional($purchasedProduct->rawMaterial)->atelier_id);
            if ($atelierId <= 0) {
                throw new \InvalidArgumentException('فروشگاه این فاکتور مشخص نیست');
            }

            $purchase->loadMissing(['installments', 'cheque']);
            $settlement = self::allocateReturnSettlement($purchase, $ratio);
            $origEarned = (float) $purchase->credit_earned;
            $creditEarnedReversed = PriceTools::roundToman($origEarned * $ratio);
            if ($creditEarnedReversed > $origEarned) {
                $creditEarnedReversed = $origEarned;
            }
            $creditRefunded = PriceTools::roundToman($settlement['wallet'] + $settlement['loyalty']);

            $needsCustomer = $creditRefunded >= 0.01 || $creditEarnedReversed >= 0.01
                || $purchase->phone || $phone;
            $customer = $needsCustomer
                ? self::resolveCustomer($purchase, $phone, $atelierId)
                : null;

            if ($customer) {
                $newCredit = max(0, PriceTools::roundToman(
                    (float) $customer->credit + $creditRefunded - $creditEarnedReversed
                ));
                $customer->credit = $newCredit;
                $customer->credit_last_updated_at = now();
                $customer->last_warning_sent_at = null;
                $customer->save();
            }

            if ($settlement['ar'] >= 0.01 && $purchase->isInstallment()) {
                self::reduceUnpaidInstallments($purchase, $settlement['ar'], $customer);
            }
            if ($settlement['cheque'] >= 0.01) {
                self::reduceOutstandingCheque($purchase, $settlement['cheque']);
            }

            $purchase->credit_earned = max(0, PriceTools::roundToman($origEarned - $creditEarnedReversed));
            $origUsed = (float) $purchase->credit_used;
            $purchase->credit_used = max(0, PriceTools::roundToman($origUsed - PriceTools::roundToman($origUsed * $ratio)));

            if ($purchasedProduct->product_id && $purchasedProduct->product) {
                $purchasedProduct->product->increment('quantity', $returnQuantity);
            } else {
                app(ShopPosSaleService::class)->restoreStock($purchasedProduct, $returnQuantity);
            }

            $purchasedProductId = (int) $purchasedProduct->id;
            if (ProductQuantityTools::isFullReturn($returnQuantity, $lineQty)) {
                $purchasedProduct->delete();
            } else {
                $purchasedProduct->quantity = ProductQuantityTools::normalize($lineQty - $returnQuantity, $unitType);
                $purchasedProduct->save();
            }

            $log = PurchaseItemReturn::create([
                'atelier_id' => $atelierId,
                'purchase_id' => $purchase->id,
                'purchased_product_id' => $purchasedProductId,
                'product_id' => $purchasedProduct->product_id,
                'produced_good_id' => $purchasedProduct->produced_good_id,
                'raw_material_id' => $purchasedProduct->raw_material_id,
                'quantity' => $returnQuantity,
                'sale_price' => $unitSale,
                'purchase_price' => $unitPurchase,
                'return_sale_total' => $returnAmount,
                'return_purchase_total' => $returnPurchaseTotal,
                'phone' => $purchase->phone,
                'payment_type' => $purchase->payment_type,
                'credit_used_refund' => $creditRefunded,
                'credit_earned_reversed' => $creditEarnedReversed,
                'size' => $purchasedProduct->size,
                'color' => $purchasedProduct->color,
                'user_name' => $userName,
                'notes' => $notes,
            ]);

            self::logReturnedProduct(
                $purchasedProduct,
                $atelierId,
                $returnQuantity,
                $returnAmount,
                $returnPurchaseTotal,
                $userName,
                $notes,
                $purchase,
                $creditRefunded,
                $creditEarnedReversed
            );

            CustomerCreditExpenseService::recordPurchaseReturn(
                $purchase,
                $log,
                $creditRefunded,
                $creditEarnedReversed,
                $userName
            );

            AccountingReturnPoster::post($log, $settlement);

            $purchase->load('purchasedProducts');
            $purchase->syncAmountsFromRemainingLines();
            $purchase->save();
            $purchase->load('purchasedProducts.product', 'purchasedProducts.producedGood', 'purchasedProducts.rawMaterial');
            if ($customer) {
                $customer->refresh();
            }

            return [
                'log' => $log,
                'returned_item' => [
                    'product_id' => $purchasedProduct->product_id,
                    'product_name' => $purchasedProduct->display_name,
                    'quantity' => $returnQuantity,
                    'sale_price' => $unitSale,
                    'purchase_price' => $unitPurchase,
                    'return_amount' => $returnAmount,
                    'return_purchase_total' => $returnPurchaseTotal,
                    'credit_refunded' => $creditRefunded,
                    'credit_used_refund' => $creditRefunded,
                    'credit_earned_reversed' => $creditEarnedReversed,
                    'unpaid_reduced' => round($settlement['ar'] + $settlement['cheque'], 2),
                ],
                'row' => PurchaseItemReturnGridService::formatTransactionRow(
                    $log->fresh(['product:id,name,barcode'])
                ),
                'phone' => $purchase->phone,
                'customer_credit' => $customer ? (float) $customer->credit : 0,
                'purchase' => $purchase,
            ];
        };

        if ($useTransaction) {
            return DB::transaction($run);
        }

        return $run();
    }

    /**
     * سهم برگشت: اول بدهی پرداخت‌نشده، بعد اعتبار برای مبلغ نقدی پرداخت‌شده.
     *
     * @return array{loyalty: float, wallet: float, ar: float, cheque: float}
     */
    public static function allocateReturnSettlement(Purchase $purchase, float $ratio): array
    {
        $ratio = min(1, max(0, $ratio));
        $purchase->loadMissing(['installments', 'cheque']);

        $loyalty = PriceTools::roundToman((float) $purchase->credit_used * $ratio);
        $wallet = PriceTools::roundToman((float) $purchase->actual_paid_amount * $ratio);
        $ar = 0.0;
        $cheque = 0.0;

        if ($purchase->isInstallment()) {
            $unpaid = (float) $purchase->installments->where('is_paid', false)->sum('amount');
            $ar = round($unpaid * $ratio, 2);
        } elseif ($purchase->isDebt() && ! $purchase->isDebtSettled()) {
            $ar = round($purchase->outstandingDebtAmount() * $ratio, 2);
        } elseif ($purchase->isCheque() && ! $purchase->isChequeSettled()) {
            $cheque = round($purchase->outstandingChequeAmount() * $ratio, 2);
        }

        return [
            'loyalty' => $loyalty,
            'wallet' => $wallet,
            'ar' => $ar,
            'cheque' => $cheque,
        ];
    }

    protected static function reduceUnpaidInstallments(Purchase $purchase, float $reduceBy, ?UserShiksho $customer): void
    {
        $left = round($reduceBy, 2);
        if ($left < 0.01) {
            return;
        }

        $unpaid = $purchase->installments
            ->where('is_paid', false)
            ->sortByDesc('installment_number')
            ->values();

        foreach ($unpaid as $row) {
            if ($left < 0.01) {
                break;
            }
            $amount = round((float) $row->amount, 2);
            $take = round(min($amount, $left), 2);
            $newAmount = round($amount - $take, 2);
            if ($newAmount < 0.01) {
                $row->delete();
            } else {
                $row->amount = $newAmount;
                $row->save();
            }
            $left = round($left - $take, 2);
        }

        $restored = round($reduceBy - $left, 2);
        if ($customer && $restored >= 0.01) {
            $customer->installment_credit = round((float) $customer->installment_credit + $restored, 2);
            $customer->save();
        }

        $purchase->unsetRelation('installments');
        $purchase->load('installments');
        $remainingUnpaid = $purchase->installments->where('is_paid', false);
        $purchase->installment_count = max(1, (int) $purchase->installments->count());
        $purchase->installment_amount = $remainingUnpaid->isEmpty()
            ? 0
            : round((float) $remainingUnpaid->avg('amount'), 2);
    }

    protected static function reduceOutstandingCheque(Purchase $purchase, float $reduceBy): void
    {
        $reduceBy = round($reduceBy, 2);
        if ($reduceBy < 0.01 || ! $purchase->cheque) {
            return;
        }

        $cheque = $purchase->cheque;
        if ($cheque->status === Cheque::STATUS_CLEARED) {
            return;
        }

        $newAmount = round((float) $cheque->amount - $reduceBy, 2);
        if ($newAmount < 0.01) {
            $cheque->update([
                'amount' => 0,
                'status' => Cheque::STATUS_CANCELLED,
                'purchase_id' => null,
            ]);
            $purchase->cheque_id = null;
        } else {
            $cheque->update(['amount' => $newAmount]);
        }
    }

    protected static function resolveCustomer(Purchase $purchase, ?string $phoneInput, int $atelierId): UserShiksho
    {
        $raw = $purchase->phone ?: $phoneInput;
        $phone = PhoneTools::normalizeIranPhone($raw);
        if (! PhoneTools::isValidIranMobile($phone)) {
            throw new \InvalidArgumentException(
                'این خرید مشتری ندارد. برای برگشت، شماره موبایل را بفرستید تا کاربر ساخته شود و اعتبار به همان حساب برگردد.'
            );
        }

        if (! $purchase->phone) {
            $purchase->phone = $phone;
            $purchase->save();
        }

        $user = UserShiksho::query()
            ->where('phone', $phone)
            ->where('atelier_id', $atelierId)
            ->first();

        if ($user) {
            return $user;
        }

        return UserShiksho::create([
            'phone' => $phone,
            'atelier_id' => $atelierId,
            'credit' => 0,
            'installment_credit' => 0,
            'credit_last_updated_at' => now(),
            'last_warning_sent_at' => null,
        ]);
    }

    protected static function logReturnedProduct(
        PurchasedProduct $purchasedProduct,
        int $atelierId,
        float $quantity,
        float $returnSaleTotal,
        float $returnPurchaseTotal,
        ?string $userName,
        ?string $notes,
        Purchase $purchase,
        float $creditRefunded,
        float $creditEarnedReversed
    ): void {
        if (! Schema::hasTable('returned_products')) {
            return;
        }

        $productId = $purchasedProduct->product_id;
        if (! $productId) {
            return;
        }

        $payload = [
            'product_id' => $productId,
            'atelier_id' => $atelierId,
            'sale_price' => $returnSaleTotal,
            'purchase_price' => $returnPurchaseTotal,
            'user_name' => $userName,
            'notes' => $notes,
        ];
        if (Schema::hasColumn('returned_products', 'purchase_id')) {
            $payload['purchase_id'] = $purchase->id;
        }
        if (Schema::hasColumn('returned_products', 'phone')) {
            $payload['phone'] = $purchase->phone;
        }
        if (Schema::hasColumn('returned_products', 'quantity')) {
            $payload['quantity'] = $quantity;
        }
        if (Schema::hasColumn('returned_products', 'credit_refunded')) {
            $payload['credit_refunded'] = $creditRefunded;
        }
        if (Schema::hasColumn('returned_products', 'credit_earned_reversed')) {
            $payload['credit_earned_reversed'] = $creditEarnedReversed;
        }

        ReturnedProduct::create($payload);
    }

    protected static function findUserShiksho(Purchase $purchase): ?UserShiksho
    {
        if (! $purchase->phone) {
            return null;
        }
        $query = UserShiksho::where('phone', $purchase->phone);
        if ($purchase->atelier_id !== null) {
            $query->where('atelier_id', $purchase->atelier_id);
        }

        return $query->first();
    }
}
