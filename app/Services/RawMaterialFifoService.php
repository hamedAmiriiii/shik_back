<?php

namespace App\Services;

use App\Models\ProducedGood;
use App\Models\ProducedGoodIngredient;
use App\Models\Production;
use App\Models\ProductionConsumption;
use App\Models\PurchaseStockConsumption;
use App\Models\RawMaterial;
use App\Models\RawMaterialLot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class RawMaterialFifoService
{
    public function kgFromGrams(float $gramsPerKg, float $productKg): float
    {
        return round(($gramsPerKg / 1000) * $productKg, 3);
    }

    /**
     * مقدار مصرف ماده / کالای تولیدی برای تولید.
     * کیلویی: مقدار فرمول «گرم در هر واحد کالا» است.
     * تعدادی: مقدار فرمول «عدد در هر واحد کالا» است (بدون تقسیم بر ۱۰۰۰).
     *
     * @param  RawMaterial|ProducedGood|null  $unitSource
     */
    public function neededQuantity($unitSource, float $recipeValue, float $productKg): float
    {
        $isPiece = false;
        if ($unitSource instanceof RawMaterial) {
            $isPiece = $unitSource->isPieceUnit();
        } elseif ($unitSource instanceof ProducedGood) {
            $isPiece = $unitSource->isPieceUnit();
        }

        if ($isPiece) {
            return round($recipeValue * $productKg, 3);
        }

        return $this->kgFromGrams($recipeValue, $productKg);
    }

    private function unitLabel($unitSource): string
    {
        if ($unitSource instanceof RawMaterial && $unitSource->isPieceUnit()) {
            return 'عدد';
        }
        if ($unitSource instanceof ProducedGood && $unitSource->isPieceUnit()) {
            return 'عدد';
        }

        return 'کیلو';
    }

    /**
     * تخصیص FIFO بدون کم کردن موجودی (برای پیش‌نمایش هزینه).
     */
    public function plan(RawMaterial $material, float $needKg, bool $lock = false)
    {
        $needKg = round($needKg, 3);
        $query = RawMaterialLot::query()
            ->where('raw_material_id', $material->id)
            ->where('remaining_kg', '>', 0)
            ->orderBy('purchased_at')
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $lots = $query->get();
        $remainingNeed = $needKg;
        $slices = [];

        foreach ($lots as $lot) {
            if ($remainingNeed <= 0) {
                break;
            }

            $available = (float) $lot->remaining_kg;
            $take = round(min($available, $remainingNeed), 3);
            if ($take <= 0) {
                continue;
            }

            $price = (float) $lot->price_per_kg;
            $slices[] = [
                'lot_id' => $lot->id,
                'lot' => $lot,
                'quantity_kg' => $take,
                'price_per_kg' => $price,
                'cost' => round($take * $price, 2),
            ];
            $remainingNeed = round($remainingNeed - $take, 3);
        }

        $shortage = max($remainingNeed, 0);

        return [
            'needed_kg' => $needKg,
            'allocated_kg' => round($needKg - $shortage, 3),
            'shortage_kg' => $shortage,
            'cost' => round(array_sum(array_column($slices, 'cost')), 2),
            'slices' => $slices,
        ];
    }

    /**
     * تخصیص FIFO از بچ‌های تولیدشدهٔ یک کالای تولیدی (نیمه‌ساخته).
     */
    public function planProducedGood(ProducedGood $ingredient, float $needKg, bool $lock = false): array
    {
        $needKg = round($needKg, 3);
        $query = Production::query()
            ->where('produced_good_id', $ingredient->id)
            ->where('remaining_kg', '>', 0)
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $productions = $query->get();
        $remainingNeed = $needKg;
        $slices = [];

        foreach ($productions as $production) {
            if ($remainingNeed <= 0) {
                break;
            }

            $available = (float) $production->remaining_kg;
            $take = round(min($available, $remainingNeed), 3);
            if ($take <= 0) {
                continue;
            }

            $price = (float) $production->cost_per_kg;
            $slices[] = [
                'source_production_id' => $production->id,
                'production' => $production,
                'quantity_kg' => $take,
                'price_per_kg' => $price,
                'cost' => round($take * $price, 2),
            ];
            $remainingNeed = round($remainingNeed - $take, 3);
        }

        $shortage = max($remainingNeed, 0);

        return [
            'needed_kg' => $needKg,
            'allocated_kg' => round($needKg - $shortage, 3),
            'shortage_kg' => $shortage,
            'cost' => round(array_sum(array_column($slices, 'cost')), 2),
            'slices' => $slices,
        ];
    }

    public function attachStock(RawMaterial $material): RawMaterial
    {
        $lots = $material->relationLoaded('lots')
            ? $material->lots
            : ($material->relationLoaded('openLots')
                ? $material->openLots
                : $material->lots()->orderBy('purchased_at')->orderBy('id')->get());

        $open = $lots->filter(function (RawMaterialLot $lot) {
            return (float) $lot->remaining_kg > 0;
        })->values();

        $stock = round((float) $open->sum('remaining_kg'), 3);
        $next = $open->first();

        $material->setAttribute('stock_kg', $stock);
        $material->setAttribute('open_lots_count', $open->count());
        $material->setAttribute('next_price_per_kg', $next ? (float) $next->price_per_kg : null);
        $material->setAttribute('lots', $lots->map(function (RawMaterialLot $lot) {
            return [
                'id' => $lot->id,
                'quantity_kg' => (float) $lot->quantity_kg,
                'remaining_kg' => (float) $lot->remaining_kg,
                'price_per_kg' => (float) $lot->price_per_kg,
                'purchased_at' => optional($lot->purchased_at)->toDateTimeString(),
                'note' => $lot->note,
            ];
        })->values());
        $material->makeHidden(['openLots']);

        return $material;
    }

    public function attachFifoCost(ProducedGood $good, float $quantityKg = 1.0, bool $preferInventoryCost = false): ProducedGood
    {
        $good->loadMissing(['ingredients.rawMaterial', 'ingredients.ingredientProducedGood']);

        $preview = $this->recipeCostPreview($good, $quantityKg);
        $stockKg = round((float) Production::where('produced_good_id', $good->id)->sum('remaining_kg'), 3);

        $total = $preview['total'];
        $lines = $preview['lines'];

        if ($preferInventoryCost && $stockKg > 0) {
            $inventory = $this->remainingInventoryCost($good, $quantityKg);
            if ($inventory['cost_per_kg'] !== null) {
                $total = $inventory['total'];
                $lines = $this->overlayInventoryOnRecipeLines(
                    $preview['lines'],
                    $inventory['by_key'],
                    $quantityKg
                );
            }
        }

        $qty = $quantityKg > 0 ? $quantityKg : 1;
        $costPerKg = round($total / $qty, 2);
        $salePrice = round((float) $good->sale_price, 2);
        $profitPerKg = round($salePrice - $costPerKg, 2);

        $good->setAttribute('quantity_kg', $quantityKg);
        $good->setAttribute('total_cost', round($total, 2));
        $good->setAttribute('cost_per_kg', $costPerKg);
        $good->setAttribute('sale_price', $salePrice);
        $good->setAttribute('profit_per_kg', $profitPerKg);
        $good->setAttribute(
            'profit_percent',
            $costPerKg > 0 ? round(($profitPerKg / $costPerKg) * 100, 2) : null
        );
        $good->setAttribute('stock_kg', $stockKg);
        $good->setAttribute('ingredient_costs', $lines);
        $good->setAttribute('stock_sufficient', $preview['sufficient']);
        $good->setAttribute('shortages', $preview['shortages']);
        $good->makeHidden('ingredients');

        return $good;
    }

    /**
     * پیش‌نمایش هزینه فرمول. کمبود موجودی با آخرین قیمت برآورد می‌شود، نه صفر.
     */
    private function recipeCostPreview(ProducedGood $good, float $quantityKg, int $depth = 0): array
    {
        $lines = [];
        $total = 0.0;
        $shortages = [];
        $sufficient = true;

        foreach ($good->ingredients as $line) {
            if ($this->lineIsNested($line)) {
                $nested = $line->ingredientProducedGood;
                $grams = (float) $line->grams_per_kg;
                $needKg = $this->neededQuantity($nested, $grams, $quantityKg);
                $plan = $nested
                    ? $this->planProducedGood($nested, $needKg)
                    : [
                        'needed_kg' => $needKg,
                        'allocated_kg' => 0,
                        'shortage_kg' => $needKg,
                        'cost' => 0,
                        'slices' => [],
                    ];

                if ($plan['shortage_kg'] > 0) {
                    $sufficient = false;
                    $shortages[] = [
                        'raw_material_id' => null,
                        'ingredient_produced_good_id' => (int) $line->ingredient_produced_good_id,
                        'name' => $nested ? $nested->name : null,
                        'needed_kg' => $plan['needed_kg'],
                        'shortage_kg' => $plan['shortage_kg'],
                    ];
                }

                $slicePayload = array_map(function ($slice) {
                    return [
                        'lot_id' => null,
                        'source_production_id' => $slice['source_production_id'],
                        'quantity_kg' => $slice['quantity_kg'],
                        'price_per_kg' => $slice['price_per_kg'],
                        'cost' => $slice['cost'],
                    ];
                }, $plan['slices']);

                $cost = $plan['cost'];
                if ($plan['shortage_kg'] > 0 && $nested) {
                    $lastPrice = $this->estimateProducedGoodUnitCost($nested, $depth);
                    if ($lastPrice !== null) {
                        $estimated = round($plan['shortage_kg'] * $lastPrice, 2);
                        $cost = round($cost + $estimated, 2);
                        $slicePayload[] = [
                            'lot_id' => null,
                            'source_production_id' => null,
                            'quantity_kg' => $plan['shortage_kg'],
                            'price_per_kg' => $lastPrice,
                            'cost' => $estimated,
                            'estimated' => true,
                        ];
                    }
                }

                $total += $cost;
                $lines[] = [
                    'id' => $line->id,
                    'raw_material_id' => null,
                    'ingredient_produced_good_id' => (int) $line->ingredient_produced_good_id,
                    'ingredient_kind' => 'produced_good',
                    'name' => $nested ? $nested->name : null,
                    'unit_type' => $nested ? $nested->unitType() : ProducedGood::UNIT_KG,
                    'grams_per_kg' => $grams,
                    'is_removable' => false,
                    'is_addable' => false,
                    'needed_kg' => $needKg,
                    'cost' => $cost,
                    'lots' => $slicePayload,
                    'shortage_kg' => $plan['shortage_kg'],
                ];

                continue;
            }

            $material = $line->rawMaterial;
            $grams = (float) $line->grams_per_kg;
            $needKg = $this->neededQuantity($material, $grams, $quantityKg);
            $plan = $material
                ? $this->plan($material, $needKg)
                : [
                    'needed_kg' => $needKg,
                    'allocated_kg' => 0,
                    'shortage_kg' => $needKg,
                    'cost' => 0,
                    'slices' => [],
                ];

            if ($plan['shortage_kg'] > 0) {
                $sufficient = false;
                $shortages[] = [
                    'raw_material_id' => $line->raw_material_id,
                    'ingredient_produced_good_id' => null,
                    'name' => $material ? $material->name : null,
                    'needed_kg' => $plan['needed_kg'],
                    'shortage_kg' => $plan['shortage_kg'],
                ];
            }

            $slicePayload = array_map(function ($slice) {
                return [
                    'lot_id' => $slice['lot_id'],
                    'quantity_kg' => $slice['quantity_kg'],
                    'price_per_kg' => $slice['price_per_kg'],
                    'cost' => $slice['cost'],
                ];
            }, $plan['slices']);

            $cost = $plan['cost'];
            if ($plan['shortage_kg'] > 0 && $material) {
                $lastPrice = $this->lastPricePerKg($material);
                if ($lastPrice !== null) {
                    $estimated = round($plan['shortage_kg'] * $lastPrice, 2);
                    $cost = round($cost + $estimated, 2);
                    $slicePayload[] = [
                        'lot_id' => null,
                        'quantity_kg' => $plan['shortage_kg'],
                        'price_per_kg' => $lastPrice,
                        'cost' => $estimated,
                        'estimated' => true,
                    ];
                }
            }

            $total += $cost;
            $lines[] = [
                'id' => $line->id,
                'raw_material_id' => $line->raw_material_id,
                'ingredient_produced_good_id' => null,
                'ingredient_kind' => 'raw_material',
                'name' => $material ? $material->name : null,
                'unit_type' => $material ? $material->unitType() : RawMaterial::UNIT_KG,
                'grams_per_kg' => $grams,
                'is_removable' => (bool) ($line->is_removable ?? false),
                'is_addable' => (bool) ($line->is_addable ?? false),
                'needed_kg' => $needKg,
                'cost' => $cost,
                'lots' => $slicePayload,
                'shortage_kg' => $plan['shortage_kg'],
            ];
        }

        return [
            'lines' => $lines,
            'total' => round($total, 2),
            'shortages' => $shortages,
            'sufficient' => $sufficient,
        ];
    }

    private function lineIsNested(ProducedGoodIngredient $line): bool
    {
        return Schema::hasColumn('produced_good_ingredients', 'ingredient_produced_good_id')
            && (int) ($line->ingredient_produced_good_id ?? 0) > 0;
    }

    /**
     * آخرین قیمت خرید ماده اولیه، حتی اگر موجودی لات صفر شده باشد.
     */
    private function lastPricePerKg(RawMaterial $material): ?float
    {
        $lot = RawMaterialLot::query()
            ->where('raw_material_id', $material->id)
            ->orderByDesc('purchased_at')
            ->orderByDesc('id')
            ->first();

        if (! $lot) {
            return null;
        }

        return (float) $lot->price_per_kg;
    }

    /**
     * برآورد هزینه واحد کالای تولیدی: آخرین تولید، وگرنه فرمول (تا عمق محدود).
     */
    private function estimateProducedGoodUnitCost(ProducedGood $good, int $depth = 0): ?float
    {
        $last = Production::query()
            ->where('produced_good_id', $good->id)
            ->orderByDesc('id')
            ->first();
        if ($last) {
            return (float) $last->cost_per_kg;
        }

        if ($depth >= 4) {
            return null;
        }

        $good->loadMissing(['ingredients.rawMaterial', 'ingredients.ingredientProducedGood']);
        $preview = $this->recipeCostPreview($good, 1.0, $depth + 1);
        if ($preview['total'] > 0) {
            return round((float) $preview['total'], 2);
        }

        return null;
    }

    /**
     * هزینه واقعی موجودی تولیدشده (میانگین وزنی بچ‌های باقی‌مانده).
     */
    private function remainingInventoryCost(ProducedGood $good, float $quantityKg): array
    {
        $productions = Production::query()
            ->where('produced_good_id', $good->id)
            ->where('remaining_kg', '>', 0)
            ->with(['consumptions.rawMaterial', 'consumptions.ingredientProducedGood'])
            ->orderBy('id')
            ->get();

        $stockKg = round((float) $productions->sum('remaining_kg'), 3);
        if ($stockKg <= 0) {
            return [
                'cost_per_kg' => null,
                'total' => 0.0,
                'by_key' => [],
            ];
        }

        $weightedCost = 0.0;
        $byKey = [];

        foreach ($productions as $production) {
            $batchKg = (float) $production->quantity_kg;
            $remaining = (float) $production->remaining_kg;
            if ($batchKg <= 0 || $remaining <= 0) {
                continue;
            }

            $weightedCost += $remaining * (float) $production->cost_per_kg;
            $weight = $remaining / $stockKg;

            foreach ($production->consumptions as $consumption) {
                $nestedId = (int) ($consumption->ingredient_produced_good_id ?? 0);
                if ($nestedId > 0) {
                    $key = 'pg:'.$nestedId;
                    if (! isset($byKey[$key])) {
                        $byKey[$key] = [
                            'kind' => 'produced_good',
                            'raw_material_id' => null,
                            'ingredient_produced_good_id' => $nestedId,
                            'name' => optional($consumption->ingredientProducedGood)->name,
                            'unit_type' => $consumption->ingredientProducedGood
                                ? $consumption->ingredientProducedGood->unitType()
                                : ProducedGood::UNIT_KG,
                            'qty_per_kg' => 0.0,
                            'cost_per_kg' => 0.0,
                            'lots' => [],
                        ];
                    }
                } else {
                    $mid = (int) $consumption->raw_material_id;
                    $key = 'rm:'.$mid;
                    if (! isset($byKey[$key])) {
                        $byKey[$key] = [
                            'kind' => 'raw_material',
                            'raw_material_id' => $mid,
                            'ingredient_produced_good_id' => null,
                            'name' => optional($consumption->rawMaterial)->name,
                            'unit_type' => $consumption->rawMaterial
                                ? $consumption->rawMaterial->unitType()
                                : RawMaterial::UNIT_KG,
                            'qty_per_kg' => 0.0,
                            'cost_per_kg' => 0.0,
                            'lots' => [],
                        ];
                    }
                }

                $qtyPerKg = ((float) $consumption->quantity_kg) / $batchKg;
                $costPerKg = ((float) $consumption->cost) / $batchKg;

                $byKey[$key]['qty_per_kg'] += $qtyPerKg * $weight;
                $byKey[$key]['cost_per_kg'] += $costPerKg * $weight;
                $byKey[$key]['lots'][] = [
                    'lot_id' => $consumption->raw_material_lot_id,
                    'source_production_id' => $consumption->source_production_id ?? null,
                    'quantity_kg_per_kg' => $qtyPerKg * $weight,
                    'price_per_kg' => (float) $consumption->price_per_kg,
                    'cost_per_kg' => $costPerKg * $weight,
                ];
            }
        }

        $costPerKg = round($weightedCost / $stockKg, 2);
        $qty = $quantityKg > 0 ? $quantityKg : 1;

        return [
            'cost_per_kg' => $costPerKg,
            'total' => round($costPerKg * $qty, 2),
            'by_key' => $byKey,
        ];
    }

    private function overlayInventoryOnRecipeLines(array $recipeLines, array $byKey, float $quantityKg): array
    {
        $qty = $quantityKg > 0 ? $quantityKg : 1;
        $used = [];

        $scaleLots = function (array $inv) use ($qty) {
            return array_map(function ($lot) use ($qty) {
                return [
                    'lot_id' => $lot['lot_id'],
                    'source_production_id' => $lot['source_production_id'] ?? null,
                    'quantity_kg' => round($lot['quantity_kg_per_kg'] * $qty, 3),
                    'price_per_kg' => $lot['price_per_kg'],
                    'cost' => round($lot['cost_per_kg'] * $qty, 2),
                ];
            }, $inv['lots']);
        };

        $lineKey = function (array $line): string {
            $nestedId = (int) ($line['ingredient_produced_good_id'] ?? 0);
            if ($nestedId > 0) {
                return 'pg:'.$nestedId;
            }

            return 'rm:'.(int) ($line['raw_material_id'] ?? 0);
        };

        $lines = array_map(function ($line) use ($byKey, $qty, $scaleLots, $lineKey, &$used) {
            $key = $lineKey($line);
            $used[$key] = true;
            if (! isset($byKey[$key])) {
                return $line;
            }

            $inv = $byKey[$key];
            $line['needed_kg'] = round($inv['qty_per_kg'] * $qty, 3);
            $line['cost'] = round($inv['cost_per_kg'] * $qty, 2);
            $line['lots'] = $scaleLots($inv);

            return $line;
        }, $recipeLines);

        foreach ($byKey as $key => $inv) {
            if (isset($used[$key])) {
                continue;
            }

            $isPiece = ($inv['unit_type'] ?? RawMaterial::UNIT_KG) === RawMaterial::UNIT_PIECE
                || ($inv['unit_type'] ?? '') === ProducedGood::UNIT_PIECE;
            $lines[] = [
                'id' => null,
                'raw_material_id' => $inv['raw_material_id'],
                'ingredient_produced_good_id' => $inv['ingredient_produced_good_id'],
                'ingredient_kind' => $inv['kind'] ?? 'raw_material',
                'name' => $inv['name'],
                'unit_type' => $inv['unit_type'] ?? RawMaterial::UNIT_KG,
                'grams_per_kg' => round($inv['qty_per_kg'] * ($isPiece ? 1 : 1000), 3),
                'needed_kg' => round($inv['qty_per_kg'] * $qty, 3),
                'cost' => round($inv['cost_per_kg'] * $qty, 2),
                'lots' => $scaleLots($inv),
                'shortage_kg' => 0,
            ];
        }

        return $lines;
    }

    /**
     * تولید واقعی: موجودی لات‌ها و بچ‌های نیمه‌ساخته به ترتیب FIFO کم می‌شود.
     */
    public function produce(ProducedGood $good, float $quantityKg, ?string $note = null): Production
    {
        $quantityKg = round($quantityKg, 3);
        if ($quantityKg <= 0) {
            throw new RuntimeException('مقدار تولید باید بیشتر از صفر باشد.');
        }

        $good->loadMissing(['ingredients.rawMaterial', 'ingredients.ingredientProducedGood']);

        return DB::transaction(function () use ($good, $quantityKg, $note) {
            $plans = [];
            foreach ($good->ingredients as $line) {
                if ($this->lineIsNested($line)) {
                    $nested = $line->ingredientProducedGood;
                    if (! $nested) {
                        throw new RuntimeException('کالای تولیدی فرمول یافت نشد.');
                    }
                    if ((int) $nested->id === (int) $good->id) {
                        throw new RuntimeException('کالا نمی‌تواند خودش را در فرمول داشته باشد.');
                    }

                    $needKg = $this->neededQuantity($nested, (float) $line->grams_per_kg, $quantityKg);
                    $plan = $this->planProducedGood($nested, $needKg, true);
                    if ($plan['shortage_kg'] > 0) {
                        throw new RuntimeException(
                            'موجودی «'.$nested->name.'» کافی نیست. کمبود: '.$plan['shortage_kg'].' '.$this->unitLabel($nested).'.'
                        );
                    }
                    $plans[] = [
                        'kind' => 'produced_good',
                        'nested' => $nested,
                        'plan' => $plan,
                    ];

                    continue;
                }

                $material = $line->rawMaterial;
                if (! $material) {
                    throw new RuntimeException('ماده اولیه فرمول یافت نشد.');
                }

                $needKg = $this->neededQuantity($material, (float) $line->grams_per_kg, $quantityKg);
                $plan = $this->plan($material, $needKg, true);
                if ($plan['shortage_kg'] > 0) {
                    throw new RuntimeException(
                        'موجودی «'.$material->name.'» کافی نیست. کمبود: '.$plan['shortage_kg'].' '.$this->unitLabel($material).'.'
                    );
                }
                $plans[] = [
                    'kind' => 'raw_material',
                    'material' => $material,
                    'plan' => $plan,
                ];
            }

            $totalCost = 0.0;
            $production = Production::create([
                'atelier_id' => $good->atelier_id,
                'produced_good_id' => $good->id,
                'quantity_kg' => $quantityKg,
                'remaining_kg' => $quantityKg,
                'total_cost' => 0,
                'cost_per_kg' => 0,
                'note' => $note,
            ]);

            $hasNestedCols = Schema::hasColumn('production_consumptions', 'ingredient_produced_good_id');

            foreach ($plans as $row) {
                if (($row['kind'] ?? '') === 'produced_good') {
                    if (! $hasNestedCols) {
                        throw new RuntimeException(
                            'ستون‌های کالای تولیدی در جدول production_consumptions وجود ندارد. migration را اجرا کنید.'
                        );
                    }
                    foreach ($row['plan']['slices'] as $slice) {
                        /** @var Production $source */
                        $source = $slice['production'];
                        $take = $slice['quantity_kg'];
                        $left = round((float) $source->remaining_kg - $take, 3);
                        if ($left < 0) {
                            throw new RuntimeException('موجودی تولید همزمان تغییر کرده است. دوباره تلاش کنید.');
                        }
                        $source->remaining_kg = $left;
                        $source->save();

                        $production->consumptions()->create([
                            'raw_material_id' => null,
                            'raw_material_lot_id' => null,
                            'ingredient_produced_good_id' => $row['nested']->id,
                            'source_production_id' => $source->id,
                            'quantity_kg' => $take,
                            'price_per_kg' => $slice['price_per_kg'],
                            'cost' => $slice['cost'],
                        ]);
                        $totalCost += $slice['cost'];
                    }

                    continue;
                }

                foreach ($row['plan']['slices'] as $slice) {
                    /** @var RawMaterialLot $lot */
                    $lot = $slice['lot'];
                    $take = $slice['quantity_kg'];
                    $left = round((float) $lot->remaining_kg - $take, 3);
                    if ($left < 0) {
                        throw new RuntimeException('موجودی لات همزمان تغییر کرده است. دوباره تلاش کنید.');
                    }
                    $lot->remaining_kg = $left;
                    $lot->save();

                    $payload = [
                        'raw_material_id' => $row['material']->id,
                        'raw_material_lot_id' => $lot->id,
                        'quantity_kg' => $take,
                        'price_per_kg' => $slice['price_per_kg'],
                        'cost' => $slice['cost'],
                    ];
                    if ($hasNestedCols) {
                        $payload['ingredient_produced_good_id'] = null;
                        $payload['source_production_id'] = null;
                    }
                    $production->consumptions()->create($payload);
                    $totalCost += $slice['cost'];
                }
            }

            $production->total_cost = round($totalCost, 2);
            $production->cost_per_kg = round($totalCost / $quantityKg, 2);
            $production->save();

            $good->refresh();
            $good->syncSalePriceFromCost((float) $production->cost_per_kg);

            AccountingProductionPoster::post($production);

            return $production->load([
                'consumptions.rawMaterial',
                'consumptions.ingredientProducedGood',
                'producedGood',
            ]);
        });
    }

    /**
     * برگشت تولید فروش‌نشده / مصرف‌نشده: مواد و نیمه‌ساخته‌ها برمی‌گردند و رکورد تولید حذف می‌شود.
     */
    public function reverseProduction(Production $production): void
    {
        DB::transaction(function () use ($production) {
            /** @var Production|null $locked */
            $locked = Production::query()->where('id', $production->id)->lockForUpdate()->first();
            if (! $locked) {
                throw new RuntimeException('تولید یافت نشد.');
            }

            $qty = round((float) $locked->quantity_kg, 3);
            $remaining = round((float) $locked->remaining_kg, 3);
            if ($remaining !== $qty) {
                throw new RuntimeException(
                    'بخشی از این تولید فروخته یا در تولید دیگری مصرف شده و قابل برگشت نیست. ابتدا فروش/مصرف را برگشت بزنید یا قیمت خرید ماده اولیه را اصلاح کنید.'
                );
            }

            $activeSale = PurchaseStockConsumption::query()
                ->where('production_id', $locked->id)
                ->whereRaw('quantity_kg > restored_kg')
                ->lockForUpdate()
                ->exists();
            if ($activeSale) {
                throw new RuntimeException(
                    'این تولید در فروش استفاده شده و قابل برگشت نیست. ابتدا فروش را برگشت بزنید یا قیمت خرید ماده اولیه را اصلاح کنید.'
                );
            }

            if (Schema::hasColumn('production_consumptions', 'source_production_id')) {
                $usedAsSource = ProductionConsumption::query()
                    ->where('source_production_id', $locked->id)
                    ->lockForUpdate()
                    ->exists();
                if ($usedAsSource) {
                    throw new RuntimeException(
                        'این تولید در تولید کالای دیگری مصرف شده و قابل برگشت نیست. ابتدا آن تولید را برگشت بزنید.'
                    );
                }
            }

            $locked->load('consumptions');
            AccountingProductionPoster::reverse($locked);

            $lotIds = $locked->consumptions
                ->pluck('raw_material_lot_id')
                ->unique()
                ->filter()
                ->values();
            $lots = RawMaterialLot::query()
                ->whereIn('id', $lotIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $sourceIds = $locked->consumptions
                ->pluck('source_production_id')
                ->unique()
                ->filter()
                ->values();
            $sources = Production::query()
                ->whereIn('id', $sourceIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($locked->consumptions as $row) {
                $sourceId = (int) ($row->source_production_id ?? 0);
                if ($sourceId > 0) {
                    $source = $sources->get($sourceId);
                    if (! $source) {
                        throw new RuntimeException('بچ تولید مصرف‌شده یافت نشد.');
                    }
                    $source->remaining_kg = round((float) $source->remaining_kg + (float) $row->quantity_kg, 3);
                    $source->save();

                    continue;
                }

                $lot = $lots->get($row->raw_material_lot_id);
                if (! $lot) {
                    throw new RuntimeException('لات ماده اولیه یافت نشد.');
                }
                $lot->remaining_kg = round((float) $lot->remaining_kg + (float) $row->quantity_kg, 3);
                $lot->save();
            }

            $locked->consumptions()->delete();
            $locked->delete();
        });
    }

    /**
     * اصلاح قیمت/اطلاعات لات. اگر در تولید مصرف شده باشد، هزینه همان تولیدها هم به‌روز می‌شود.
     */
    public function updateLot(RawMaterialLot $lot, array $fields): RawMaterialLot
    {
        return DB::transaction(function () use ($lot, $fields) {
            /** @var RawMaterialLot|null $locked */
            $locked = RawMaterialLot::query()->where('id', $lot->id)->lockForUpdate()->first();
            if (! $locked) {
                throw new RuntimeException('لات یافت نشد.');
            }

            $priceChanged = false;
            if (array_key_exists('price_per_kg', $fields) && $fields['price_per_kg'] !== null) {
                $newPrice = round((float) $fields['price_per_kg'], 2);
                if ($newPrice !== round((float) $locked->price_per_kg, 2)) {
                    $priceChanged = true;
                    $locked->price_per_kg = $newPrice;
                }
            }
            if (array_key_exists('note', $fields)) {
                $locked->note = $fields['note'];
            }
            if (array_key_exists('purchased_at', $fields)) {
                $locked->purchased_at = $fields['purchased_at'];
            }

            $locked->save();

            if ($priceChanged) {
                $this->recalculateProductionsForLot($locked);
            }

            return $locked->fresh();
        });
    }

    private function recalculateProductionsForLot(RawMaterialLot $lot): void
    {
        $price = (float) $lot->price_per_kg;
        $consumptions = ProductionConsumption::query()
            ->where('raw_material_lot_id', $lot->id)
            ->lockForUpdate()
            ->get();

        $productionIds = [];
        foreach ($consumptions as $row) {
            $row->price_per_kg = $price;
            $row->cost = round((float) $row->quantity_kg * $price, 2);
            $row->save();
            $productionIds[] = (int) $row->production_id;
        }

        $productionIds = array_values(array_unique($productionIds));
        if (count($productionIds) === 0) {
            return;
        }

        $productions = Production::query()
            ->whereIn('id', $productionIds)
            ->lockForUpdate()
            ->get();

        foreach ($productions as $production) {
            $production->load('consumptions');
            $total = round((float) $production->consumptions->sum('cost'), 2);
            $qty = (float) $production->quantity_kg;
            $production->total_cost = $total;
            $production->cost_per_kg = $qty > 0 ? round($total / $qty, 2) : 0;
            $production->save();
        }
    }
}
