<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\PurchaseItemReturn;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * ردپای اقلام فاکتور بعد از برگشت جزئی/کامل برای نمایش در هیستوری.
 */
class PurchaseHistoryEnricher
{
    /**
     * @param  array<string, mixed>  $purchaseData
     * @param  Collection<int, PurchaseItemReturn>|null  $returns
     * @return array<string, mixed>
     */
    public static function enrichArray(array $purchaseData, ?Collection $returns = null): array
    {
        $purchaseId = (int) ($purchaseData['id'] ?? 0);
        if ($purchaseId <= 0) {
            return $purchaseData;
        }

        if ($returns === null) {
            $returns = self::returnsForPurchaseIds([$purchaseId])->get($purchaseId, collect());
        }

        $lines = is_array($purchaseData['purchased_products'] ?? null)
            ? $purchaseData['purchased_products']
            : [];

        $purchaseData['purchased_products'] = self::buildHistoryLines($lines, $returns);

        return $purchaseData;
    }

    /**
     * @param  iterable<int, Purchase>  $purchases
     * @return Collection<int, Collection<int, PurchaseItemReturn>>
     */
    public static function returnsForPurchases(iterable $purchases): Collection
    {
        $ids = collect($purchases)->pluck('id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        return self::returnsForPurchaseIds($ids);
    }

    /**
     * @param  array<int, int>  $purchaseIds
     * @return Collection<int, Collection<int, PurchaseItemReturn>>
     */
    public static function returnsForPurchaseIds(array $purchaseIds): Collection
    {
        if ($purchaseIds === [] || ! Schema::hasTable('purchase_item_returns')) {
            return collect();
        }

        return PurchaseItemReturn::query()
            ->whereIn('purchase_id', $purchaseIds)
            ->with(['product:id,name,barcode', 'producedGood:id,name', 'rawMaterial:id,name'])
            ->orderBy('id')
            ->get()
            ->groupBy(fn (PurchaseItemReturn $row) => (int) $row->purchase_id);
    }

    /**
     * @param  array<int, array<string, mixed>>  $purchasedProducts
     * @param  Collection<int, PurchaseItemReturn>  $returns
     * @return array<int, array<string, mixed>>
     */
    public static function buildHistoryLines(array $purchasedProducts, Collection $returns): array
    {
        $returnedByLineId = [];
        foreach ($returns as $row) {
            $lineId = (int) ($row->purchased_product_id ?? 0);
            if ($lineId <= 0) {
                continue;
            }
            $returnedByLineId[$lineId] = ($returnedByLineId[$lineId] ?? 0) + (float) $row->quantity;
        }

        $lines = [];
        $seenLineIds = [];

        foreach ($purchasedProducts as $pp) {
            if (! is_array($pp)) {
                continue;
            }
            $id = (int) ($pp['id'] ?? 0);
            if ($id > 0) {
                $seenLineIds[$id] = true;
            }
            $qty = (float) ($pp['quantity'] ?? 0);
            $returned = round((float) ($returnedByLineId[$id] ?? 0), 3);
            $pp['returned_quantity'] = $returned;
            $pp['original_quantity'] = round($qty + $returned, 3);
            $pp['is_fully_returned'] = $qty <= 0.0001 && $returned > 0.0001;
            $pp['is_partially_returned'] = $qty > 0.0001 && $returned > 0.0001;
            $pp['from_return_history'] = false;
            if (empty($pp['item_name']) && empty($pp['display_name'])) {
                $pp['item_name'] = self::lineDisplayNameFromArray($pp);
            }
            $lines[] = $pp;
        }

        $orphanReturns = $returns->filter(function (PurchaseItemReturn $row) use ($seenLineIds) {
            $lineId = (int) ($row->purchased_product_id ?? 0);

            return $lineId <= 0 || ! isset($seenLineIds[$lineId]);
        });

        if ($orphanReturns->isEmpty()) {
            return $lines;
        }

        $grouped = $orphanReturns->groupBy(function (PurchaseItemReturn $row) {
            $lineId = (int) ($row->purchased_product_id ?? 0);
            if ($lineId > 0) {
                return 'line:'.$lineId;
            }

            return 'sku:'
                .(int) ($row->product_id ?? 0).':'
                .(int) ($row->produced_good_id ?? 0).':'
                .(int) ($row->raw_material_id ?? 0).':'
                .(string) ($row->size ?? '').':'
                .(string) ($row->color ?? '').':'
                .round((float) $row->sale_price, 2);
        });

        foreach ($grouped as $group) {
            /** @var PurchaseItemReturn $first */
            $first = $group->first();
            $returnedQty = round((float) $group->sum(fn (PurchaseItemReturn $r) => (float) $r->quantity), 3);
            if ($returnedQty <= 0) {
                continue;
            }

            $product = $first->relationLoaded('product') ? $first->product : null;
            $produced = $first->relationLoaded('producedGood') ? $first->producedGood : null;
            $raw = $first->relationLoaded('rawMaterial') ? $first->rawMaterial : null;

            $lines[] = [
                'id' => $first->purchased_product_id
                    ? (int) $first->purchased_product_id
                    : ('history-return-'.(int) $first->id),
                'purchase_id' => (int) $first->purchase_id,
                'product_id' => $first->product_id,
                'produced_good_id' => $first->produced_good_id,
                'raw_material_id' => $first->raw_material_id,
                'item_name' => $first->itemName(),
                'display_name' => $first->itemName(),
                'quantity' => 0,
                'returned_quantity' => $returnedQty,
                'original_quantity' => $returnedQty,
                'sale_price' => (float) $first->sale_price,
                'purchase_price' => (float) $first->purchase_price,
                'size' => $first->size,
                'color' => $first->color,
                'is_fully_returned' => true,
                'is_partially_returned' => false,
                'from_return_history' => true,
                'product' => $product ? [
                    'id' => $product->id,
                    'name' => $product->name,
                    'barcode' => $product->barcode ?? null,
                ] : null,
                'produced_good' => $produced ? ['id' => $produced->id, 'name' => $produced->name] : null,
                'raw_material' => $raw ? ['id' => $raw->id, 'name' => $raw->name] : null,
                'barcode' => $product ? ($product->barcode ?? null) : null,
            ];
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $pp
     */
    protected static function lineDisplayNameFromArray(array $pp): string
    {
        if (! empty($pp['item_name'])) {
            return (string) $pp['item_name'];
        }
        if (! empty($pp['display_name'])) {
            return (string) $pp['display_name'];
        }
        foreach (['product', 'produced_good', 'producedGood', 'raw_material', 'rawMaterial'] as $key) {
            $nested = $pp[$key] ?? null;
            if (is_array($nested) && ! empty($nested['name'])) {
                return (string) $nested['name'];
            }
        }

        return 'کالا';
    }
}
