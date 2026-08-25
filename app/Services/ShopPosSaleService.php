<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProducedGood;
use App\Models\Production;
use App\Models\PurchasedProduct;
use App\Models\PurchaseStockConsumption;
use App\Models\RawMaterial;
use App\Models\RawMaterialLot;
use App\Tools\PriceTools;
use App\Tools\ProductQuantityTools;
use RuntimeException;

class ShopPosSaleService
{
    public const KIND_PRODUCT = 'product';

    public const KIND_PRODUCED_GOOD = 'produced_good';

    public const KIND_RAW_MATERIAL = 'raw_material';

    /** @var RawMaterialFifoService */
    private $fifo;

    public function __construct(RawMaterialFifoService $fifo)
    {
        $this->fifo = $fifo;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function prepareLines(array $inputLines, ?int $staffAtelierId): array
    {
        $prepared = [];
        foreach ($inputLines as $row) {
            $prepared[] = $this->prepareLine($row, $staffAtelierId);
        }

        $atelierIds = array_unique(array_filter(array_column($prepared, 'atelier_id')));
        if ($staffAtelierId !== null) {
            foreach ($prepared as $line) {
                if ((int) $line['atelier_id'] !== (int) $staffAtelierId) {
                    throw new RuntimeException('یک یا چند کالا متعلق به فروشگاه شما نیست');
                }
            }
        } elseif (count($atelierIds) > 1) {
            throw new RuntimeException('همه کالاها باید متعلق به یک فروشگاه باشند');
        }

        return $prepared;
    }

    public function purchaseAtelierId(array $prepared, ?int $staffAtelierId): ?int
    {
        if ($staffAtelierId !== null) {
            return (int) $staffAtelierId;
        }

        $ids = array_unique(array_filter(array_column($prepared, 'atelier_id')));

        return empty($ids) ? null : (int) reset($ids);
    }

    /**
     * @param array<int, array<string, mixed>> $prepared
     */
    public function assertStock(array $prepared): void
    {
        foreach ($prepared as $line) {
            $qty = (float) $line['quantity'];
            $name = $line['item_name'];
            if ($line['kind'] === self::KIND_PRODUCT) {
                /** @var Product $product */
                $product = $line['model'];
                $unitType = $product->unit_type ?? Product::UNIT_PIECE;
                if ($error = ProductQuantityTools::validateSaleQuantity($qty, $unitType)) {
                    throw new RuntimeException($name.': '.$error);
                }
                if (! ProductQuantityTools::hasSufficientStock($product->quantity, $qty)) {
                    $unitLabel = ProductQuantityTools::unitLabel($unitType);
                    throw new RuntimeException(
                        "موجودی محصول '{$name}' کافی نیست. موجودی: {$product->quantity} {$unitLabel}، درخواستی: {$qty} {$unitLabel}"
                    );
                }
                continue;
            }

            if ($error = ProductQuantityTools::validateSaleQuantity($qty, Product::UNIT_KG)) {
                throw new RuntimeException($name.': '.$error);
            }

            $stock = $this->availableKg($line);
            if (! ProductQuantityTools::hasSufficientStock($stock, $qty)) {
                throw new RuntimeException(
                    "موجودی «{$name}» کافی نیست. موجودی: {$stock} کیلو، درخواستی: {$qty} کیلو"
                );
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $prepared
     * @param array<int, PurchasedProduct> $createdLines
     */
    public function commitStock(array $prepared, array $createdLines): void
    {
        foreach ($prepared as $i => $line) {
            $purchased = $createdLines[$i];
            $qty = (float) $line['quantity'];

            if ($line['kind'] === self::KIND_PRODUCT) {
                /** @var Product $product */
                $product = $line['model'];
                $product->decrement('quantity', $qty);
                continue;
            }

            if ($line['kind'] === self::KIND_PRODUCED_GOOD) {
                $this->consumeProductions($purchased, (int) $line['produced_good_id'], $qty);
                continue;
            }

            $this->consumeRawMaterialLots($purchased, (int) $line['raw_material_id'], $qty);
        }
    }

    public function restoreStock(PurchasedProduct $purchasedProduct, float $quantityKg): void
    {
        if ($purchasedProduct->product_id && $purchasedProduct->product) {
            $purchasedProduct->product->increment('quantity', $quantityKg);

            return;
        }

        $left = round($quantityKg, 3);
        $rows = PurchaseStockConsumption::query()
            ->where('purchased_product_id', $purchasedProduct->id)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        foreach ($rows as $row) {
            if ($left <= 0) {
                break;
            }
            $available = round((float) $row->quantity_kg - (float) $row->restored_kg, 3);
            $take = round(min($available, $left), 3);
            if ($take <= 0) {
                continue;
            }

            if ($row->production_id) {
                Production::where('id', $row->production_id)->increment('remaining_kg', $take);
            }
            if ($row->raw_material_lot_id) {
                RawMaterialLot::where('id', $row->raw_material_lot_id)->increment('remaining_kg', $take);
            }

            $row->restored_kg = round((float) $row->restored_kg + $take, 3);
            $row->save();
            $left = round($left - $take, 3);
        }

        if ($left > 0) {
            throw new RuntimeException('امکان برگشت موجودی این کالا وجود ندارد.');
        }
    }

    private function prepareLine(array $row, ?int $staffAtelierId): array
    {
        $productId = $row['product_id'] ?? null;
        $goodId = $row['produced_good_id'] ?? null;
        $materialId = $row['raw_material_id'] ?? null;

        if (! empty($row['item_type']) && $row['item_type'] === self::KIND_PRODUCED_GOOD && empty($goodId) && ! empty($productId)) {
            $goodId = $productId;
            $productId = null;
        }

        $filled = (int) ! empty($productId) + (int) ! empty($goodId) + (int) ! empty($materialId);
        if ($filled !== 1) {
            throw new RuntimeException('هر قلم باید فقط یکی از product_id، produced_good_id یا raw_material_id را داشته باشد.');
        }

        if (! empty($goodId)) {
            return $this->prepareProducedGoodLine($row, (int) $goodId);
        }

        if (! empty($materialId)) {
            return $this->prepareRawMaterialLine($row, (int) $materialId);
        }

        $productQuery = Product::query()->where('id', $productId);
        if ($staffAtelierId !== null) {
            $productQuery->where('atelier_id', $staffAtelierId);
        }
        $product = $productQuery->first();
        if (! $product) {
            $goodQuery = ProducedGood::query()->where('id', $productId);
            if ($staffAtelierId !== null) {
                $goodQuery->where('atelier_id', $staffAtelierId);
            }
            $good = $goodQuery->first();
            if ($good) {
                return $this->prepareProducedGoodLine($row, (int) $good->id);
            }
            throw new RuntimeException('محصول یافت نشد');
        }
        $unitType = $product->unit_type ?? Product::UNIT_PIECE;
        $qty = ProductQuantityTools::normalize($row['quantity'], $unitType);
        $salePrice = $this->resolveSalePrice($row, (float) $product->sale_price);

        return [
            'kind' => self::KIND_PRODUCT,
            'product_id' => $product->id,
            'produced_good_id' => null,
            'raw_material_id' => null,
            'item_name' => $product->name,
            'atelier_id' => $product->atelier_id,
            'quantity' => $qty,
            'sale_price' => $salePrice,
            'purchase_price' => $product->purchase_price,
            'size' => $row['size'] ?? null,
            'color' => $row['color'] ?? null,
            'model' => $product,
        ];
    }

    private function prepareProducedGoodLine(array $row, int $goodId): array
    {
        $good = ProducedGood::find($goodId);
        if (! $good) {
            throw new RuntimeException('کالای تولیدی یافت نشد');
        }
        $qty = ProductQuantityTools::normalize($row['quantity'], Product::UNIT_KG);
        $salePrice = $this->resolveSalePrice($row, (float) $good->sale_price);

        return [
            'kind' => self::KIND_PRODUCED_GOOD,
            'product_id' => null,
            'produced_good_id' => $good->id,
            'raw_material_id' => null,
            'item_name' => $good->name,
            'atelier_id' => $good->atelier_id,
            'quantity' => $qty,
            'sale_price' => $salePrice,
            'purchase_price' => $this->producedGoodCostPerKg($good, $qty),
            'size' => $row['size'] ?? null,
            'color' => $row['color'] ?? null,
            'model' => $good,
        ];
    }

    private function prepareRawMaterialLine(array $row, int $materialId): array
    {
        $material = RawMaterial::find($materialId);
        if (! $material) {
            throw new RuntimeException('ماده اولیه یافت نشد');
        }
        $qty = ProductQuantityTools::normalize($row['quantity'], Product::UNIT_KG);
        $salePrice = $this->resolveSalePrice($row, (float) $material->sale_price);
        $plan = $this->fifo->plan($material, $qty);

        return [
            'kind' => self::KIND_RAW_MATERIAL,
            'product_id' => null,
            'produced_good_id' => null,
            'raw_material_id' => $material->id,
            'item_name' => $material->name,
            'atelier_id' => $material->atelier_id,
            'quantity' => $qty,
            'sale_price' => $salePrice,
            'purchase_price' => $qty > 0 ? round($plan['cost'] / $qty, 2) : 0,
            'size' => $row['size'] ?? null,
            'color' => $row['color'] ?? null,
            'model' => $material,
        ];
    }

    private function resolveSalePrice(array $row, float $baseSalePrice): float
    {
        if (isset($row['sale_price']) && $row['sale_price'] !== null) {
            return PriceTools::roundSalePrice((float) $row['sale_price']);
        }
        if (isset($row['discount_percent']) && $row['discount_percent'] > 0) {
            $discountAmount = ($baseSalePrice * $row['discount_percent']) / 100;

            return PriceTools::roundSalePrice((float) max(0, $baseSalePrice - $discountAmount));
        }

        return PriceTools::roundSalePrice($baseSalePrice);
    }

    private function availableKg(array $line): float
    {
        if ($line['kind'] === self::KIND_PRODUCED_GOOD) {
            return round((float) Production::where('produced_good_id', $line['produced_good_id'])->sum('remaining_kg'), 3);
        }

        return round((float) RawMaterialLot::where('raw_material_id', $line['raw_material_id'])->sum('remaining_kg'), 3);
    }

    private function producedGoodCostPerKg(ProducedGood $good, float $qty): float
    {
        if ($qty <= 0) {
            return 0;
        }
        $productions = Production::where('produced_good_id', $good->id)
            ->where('remaining_kg', '>', 0)
            ->orderBy('id')
            ->get();

        $need = $qty;
        $cost = 0.0;
        foreach ($productions as $production) {
            if ($need <= 0) {
                break;
            }
            $take = round(min((float) $production->remaining_kg, $need), 3);
            $cost += $take * (float) $production->cost_per_kg;
            $need = round($need - $take, 3);
        }

        $allocated = round($qty - max($need, 0), 3);

        return $allocated > 0 ? round($cost / $allocated, 2) : 0;
    }

    private function consumeProductions(PurchasedProduct $purchased, int $producedGoodId, float $needKg): void
    {
        $need = round($needKg, 3);
        $productions = Production::where('produced_good_id', $producedGoodId)
            ->where('remaining_kg', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($productions as $production) {
            if ($need <= 0) {
                break;
            }
            $take = round(min((float) $production->remaining_kg, $need), 3);
            $left = round((float) $production->remaining_kg - $take, 3);
            $production->remaining_kg = $left;
            $production->save();

            $price = (float) $production->cost_per_kg;
            PurchaseStockConsumption::create([
                'purchased_product_id' => $purchased->id,
                'production_id' => $production->id,
                'quantity_kg' => $take,
                'restored_kg' => 0,
                'price_per_kg' => $price,
                'cost' => round($take * $price, 2),
            ]);
            $need = round($need - $take, 3);
        }

        if ($need > 0) {
            throw new RuntimeException('موجودی کالای تولیدی کافی نیست.');
        }
    }

    private function consumeRawMaterialLots(PurchasedProduct $purchased, int $rawMaterialId, float $needKg): void
    {
        $material = RawMaterial::findOrFail($rawMaterialId);
        $plan = $this->fifo->plan($material, $needKg, true);
        if ($plan['shortage_kg'] > 0) {
            throw new RuntimeException('موجودی «'.$material->name.'» کافی نیست. کمبود: '.$plan['shortage_kg'].' کیلو.');
        }

        foreach ($plan['slices'] as $slice) {
            /** @var RawMaterialLot $lot */
            $lot = $slice['lot'];
            $take = $slice['quantity_kg'];
            $left = round((float) $lot->remaining_kg - $take, 3);
            if ($left < 0) {
                throw new RuntimeException('موجودی لات همزمان تغییر کرده است. دوباره تلاش کنید.');
            }
            $lot->remaining_kg = $left;
            $lot->save();

            PurchaseStockConsumption::create([
                'purchased_product_id' => $purchased->id,
                'raw_material_lot_id' => $lot->id,
                'quantity_kg' => $take,
                'restored_kg' => 0,
                'price_per_kg' => $slice['price_per_kg'],
                'cost' => $slice['cost'],
            ]);
        }
    }
}
