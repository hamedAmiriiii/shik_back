<?php

namespace App\Services;

use App\Models\OilVisit;
use App\Models\OilVisitItem;
use App\Models\Purchase;
use App\Models\PurchasedProduct;
use Illuminate\Support\Facades\Schema;

/**
 * ثبت فروش نقدی تعویض روغن روی همان جدول purchases فروشگاه.
 * سود گزارش = فروش − بهای خرید اقلام (مثل فاکتور POS).
 */
class OilVisitSaleService
{
    public static function post(OilVisit $visit): ?Purchase
    {
        if (! Schema::hasTable('purchases') || ! Schema::hasTable('purchased_products')) {
            return null;
        }
        if (! $visit->relationLoaded('items')) {
            $visit->load('items');
        }

        $lines = [];
        $saleTotal = 0.0;
        foreach ($visit->items as $item) {
            $sale = round((float) ($item->sale_price ?? 0), 2);
            $cost = round((float) ($item->purchase_price ?? 0), 2);
            if ($sale < 0.01) {
                continue;
            }
            $lines[] = [
                'item_name' => self::lineName($item),
                'purchase_price' => $cost,
                'sale_price' => $sale,
            ];
            $saleTotal = round($saleTotal + $sale, 2);
        }
        if ($lines === [] || $saleTotal < 0.01) {
            return null;
        }

        if (Schema::hasColumn('purchases', 'oil_visit_id')) {
            $existing = Purchase::query()->where('oil_visit_id', $visit->id)->first();
            if ($existing) {
                return $existing;
            }
        }

        $payload = [
            'phone' => $visit->phone,
            'total_amount' => $saleTotal,
            'atelier_id' => (int) $visit->atelier_id,
        ];
        if (Schema::hasColumn('purchases', 'discount_amount')) {
            $payload['discount_amount'] = 0;
        }
        if (Schema::hasColumn('purchases', 'credit_used')) {
            $payload['credit_used'] = 0;
        }
        if (Schema::hasColumn('purchases', 'credit_earned')) {
            $payload['credit_earned'] = 0;
        }
        if (Schema::hasColumn('purchases', 'payment_type')) {
            $payload['payment_type'] = 'cash';
        }
        if (Schema::hasColumn('purchases', 'cash_amount')) {
            $payload['cash_amount'] = $saleTotal;
        }
        if (Schema::hasColumn('purchases', 'card_amount')) {
            $payload['card_amount'] = 0;
        }
        if (Schema::hasColumn('purchases', 'oil_visit_id')) {
            $payload['oil_visit_id'] = (int) $visit->id;
        }

        $purchase = Purchase::create($payload);

        foreach ($lines as $line) {
            $row = [
                'purchase_id' => $purchase->id,
                'item_name' => $line['item_name'],
                'quantity' => 1,
                'purchase_price' => $line['purchase_price'],
                'sale_price' => $line['sale_price'],
            ];
            if (Schema::hasColumn('purchased_products', 'product_id')) {
                $row['product_id'] = null;
            }
            PurchasedProduct::create($row);
        }

        return $purchase;
    }

    protected static function lineName(OilVisitItem $item): string
    {
        $kind = $item->kind ? \App\Models\OilProduct::kindLabel((string) $item->kind) : '';
        $name = trim((string) $item->product_name);
        if ($kind !== '' && $name !== '') {
            return $kind.' '.$name;
        }

        return $name !== '' ? $name : $kind;
    }
}
