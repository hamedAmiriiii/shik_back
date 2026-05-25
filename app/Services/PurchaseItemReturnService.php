<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\PurchaseItemReturn;
use App\Models\PurchasedProduct;
use App\Models\Product;
use App\Models\UserShiksho;
use App\Tools\PriceTools;
use Carbon\Carbon;
use Morilog\Jalali\Jalalian;

class PurchaseItemReturnService
{
    /**
     * برگشت یک یا چند عدد از خط فاکتور + ثبت تراکنش در purchase_item_returns.
     *
     * @return array<string, mixed>
     */
    public static function processReturn(
        Purchase $purchase,
        PurchasedProduct $purchasedProduct,
        int $returnQuantity,
        ?string $userName = null,
        ?string $notes = null
    ): array {
        if ($purchasedProduct->purchase_id !== $purchase->id) {
            throw new \InvalidArgumentException('این محصول متعلق به این خرید نیست');
        }

        $lineQty = (int) $purchasedProduct->quantity;
        if ($returnQuantity < 1 || $returnQuantity > $lineQty) {
            throw new \InvalidArgumentException(
                "تعداد برگشت باید بین ۱ و {$lineQty} باشد"
            );
        }

        $product = $purchasedProduct->product;
        if (! $product instanceof Product) {
            throw new \InvalidArgumentException('محصول یافت نشد');
        }

        $unitSale = (float) $purchasedProduct->sale_price;
        $unitPurchase = (float) $purchasedProduct->purchase_price;
        $returnAmount = round($unitSale * $returnQuantity, 2);
        $returnPurchaseTotal = round($unitPurchase * $returnQuantity, 2);

        $purchase->load('purchasedProducts');
        $lineTotalBeforeReturn = (float) $purchase->purchasedProducts->sum(function ($pp) {
            return (float) $pp->sale_price * (int) $pp->quantity;
        });
        $ratio = $lineTotalBeforeReturn > 0
            ? min(1, $returnAmount / $lineTotalBeforeReturn)
            : 1;

        $creditUsedRefund = PriceTools::roundToThousand((float) $purchase->credit_used * $ratio);

        if ($creditUsedRefund > 0 && $purchase->phone) {
            $userShiksho = self::findUserShiksho($purchase);
            if ($userShiksho) {
                $userShiksho->credit = (float) $userShiksho->credit + $creditUsedRefund;
                $userShiksho->save();
            }
        }

        $creditEarnedReversed = 0.0;
        if ($purchase->credit_earned > 0 && $purchase->phone) {
            $creditEarnedReversed = (float) UserShiksho::calculateCredit($returnAmount);
            $purchase->credit_earned = max(0, (float) $purchase->credit_earned - $creditEarnedReversed);

            $userShiksho = self::findUserShiksho($purchase);
            if ($userShiksho && $userShiksho->credit >= $creditEarnedReversed) {
                $userShiksho->credit = max(0, (float) $userShiksho->credit - $creditEarnedReversed);
                $userShiksho->save();
            }
        }

        $product->increment('quantity', $returnQuantity);

        $purchasedProductId = (int) $purchasedProduct->id;
        if ($returnQuantity >= $lineQty) {
            $purchasedProduct->delete();
        } else {
            $purchasedProduct->quantity = $lineQty - $returnQuantity;
            $purchasedProduct->save();
        }

        $atelierId = (int) ($purchase->atelier_id ?? $product->atelier_id);
        if ($atelierId <= 0) {
            throw new \InvalidArgumentException('فروشگاه این فاکتور مشخص نیست');
        }

        $log = PurchaseItemReturn::create([
            'atelier_id' => $atelierId,
            'purchase_id' => $purchase->id,
            'purchased_product_id' => $purchasedProductId,
            'product_id' => $purchasedProduct->product_id,
            'quantity' => $returnQuantity,
            'sale_price' => $unitSale,
            'purchase_price' => $unitPurchase,
            'return_sale_total' => $returnAmount,
            'return_purchase_total' => $returnPurchaseTotal,
            'phone' => $purchase->phone,
            'payment_type' => $purchase->payment_type,
            'credit_used_refund' => $creditUsedRefund,
            'credit_earned_reversed' => $creditEarnedReversed,
            'size' => $purchasedProduct->size,
            'color' => $purchasedProduct->color,
            'user_name' => $userName,
            'notes' => $notes,
        ]);

        $purchase->load('purchasedProducts');
        $purchase->syncAmountsFromRemainingLines();
        $purchase->save();
        $purchase->load('purchasedProducts.product');

        return [
            'log' => $log,
            'returned_item' => [
                'product_id' => $purchasedProduct->product_id,
                'product_name' => $product->name,
                'quantity' => $returnQuantity,
                'sale_price' => $unitSale,
                'purchase_price' => $unitPurchase,
                'return_amount' => $returnAmount,
                'return_purchase_total' => $returnPurchaseTotal,
                'credit_used_refund' => $creditUsedRefund,
                'credit_earned_reversed' => $creditEarnedReversed,
            ],
            'row' => PurchaseItemReturnGridService::formatTransactionRow(
                $log->fresh(['product:id,name,barcode'])
            ),
        ];
    }

    protected static function findUserShiksho(Purchase $purchase): ?UserShiksho
    {
        $query = UserShiksho::where('phone', $purchase->phone);
        if ($purchase->atelier_id !== null) {
            $query->where('atelier_id', $purchase->atelier_id);
        }

        return $query->first();
    }
}
