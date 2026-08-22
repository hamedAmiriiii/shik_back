<?php

namespace App\Services;

use App\Models\ProducedGood;
use App\Models\Production;
use App\Models\RawMaterial;
use App\Models\RawMaterialLot;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RawMaterialFifoService
{
    public function kgFromGrams(float $gramsPerKg, float $productKg): float
    {
        return round(($gramsPerKg / 1000) * $productKg, 3);
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

    public function attachFifoCost(ProducedGood $good, float $quantityKg = 1.0): ProducedGood
    {
        $good->loadMissing('ingredients.rawMaterial');

        $lines = [];
        $total = 0.0;
        $shortages = [];
        $sufficient = true;

        foreach ($good->ingredients as $line) {
            $material = $line->rawMaterial;
            $grams = (float) $line->grams_per_kg;
            $needKg = $this->kgFromGrams($grams, $quantityKg);
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

            $total += $plan['cost'];
            $lines[] = [
                'id' => $line->id,
                'raw_material_id' => $line->raw_material_id,
                'name' => $material ? $material->name : null,
                'grams_per_kg' => $grams,
                'needed_kg' => $needKg,
                'cost' => $plan['cost'],
                'lots' => $slicePayload,
                'shortage_kg' => $plan['shortage_kg'],
            ];
        }

        $qty = $quantityKg > 0 ? $quantityKg : 1;
        $good->setAttribute('quantity_kg', $quantityKg);
        $good->setAttribute('total_cost', round($total, 2));
        $good->setAttribute('cost_per_kg', round($total / $qty, 2));
        $good->setAttribute('ingredient_costs', $lines);
        $good->setAttribute('stock_sufficient', $sufficient);
        $good->setAttribute('shortages', $shortages);
        $good->makeHidden('ingredients');

        return $good;
    }

    /**
     * تولید واقعی: موجودی لات‌ها به ترتیب خرید کم می‌شود.
     */
    public function produce(ProducedGood $good, float $quantityKg, ?string $note = null): Production
    {
        $quantityKg = round($quantityKg, 3);
        if ($quantityKg <= 0) {
            throw new RuntimeException('مقدار تولید باید بیشتر از صفر باشد.');
        }

        $good->loadMissing('ingredients.rawMaterial');

        return DB::transaction(function () use ($good, $quantityKg, $note) {
            $plans = [];
            foreach ($good->ingredients as $line) {
                $material = $line->rawMaterial;
                if (! $material) {
                    throw new RuntimeException('ماده اولیه فرمول یافت نشد.');
                }

                $needKg = $this->kgFromGrams((float) $line->grams_per_kg, $quantityKg);
                $plan = $this->plan($material, $needKg, true);
                if ($plan['shortage_kg'] > 0) {
                    throw new RuntimeException(
                        'موجودی «'.$material->name.'» کافی نیست. کمبود: '.$plan['shortage_kg'].' کیلو.'
                    );
                }
                $plans[] = [
                    'material' => $material,
                    'plan' => $plan,
                ];
            }

            $totalCost = 0.0;
            $production = Production::create([
                'atelier_id' => $good->atelier_id,
                'produced_good_id' => $good->id,
                'quantity_kg' => $quantityKg,
                'total_cost' => 0,
                'cost_per_kg' => 0,
                'note' => $note,
            ]);

            foreach ($plans as $row) {
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

                    $production->consumptions()->create([
                        'raw_material_id' => $row['material']->id,
                        'raw_material_lot_id' => $lot->id,
                        'quantity_kg' => $take,
                        'price_per_kg' => $slice['price_per_kg'],
                        'cost' => $slice['cost'],
                    ]);
                    $totalCost += $slice['cost'];
                }
            }

            $production->total_cost = round($totalCost, 2);
            $production->cost_per_kg = round($totalCost / $quantityKg, 2);
            $production->save();

            return $production->load(['consumptions.rawMaterial', 'producedGood']);
        });
    }
}
