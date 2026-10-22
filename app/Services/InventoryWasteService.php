<?php

namespace App\Services;

use App\Models\InventoryWaste;
use App\Models\ProducedGood;
use App\Models\Production;
use App\Models\RawMaterial;
use App\Models\RawMaterialLot;
use App\Tools\ProductQuantityTools;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class InventoryWasteService
{
    /** @var RawMaterialFifoService */
    private $fifo;

    public function __construct(RawMaterialFifoService $fifo)
    {
        $this->fifo = $fifo;
    }

    public function wasteRawMaterial(RawMaterial $material, float $quantityKg, ?string $note = null, $wastedAt = null): InventoryWaste
    {
        $this->assertTablesReady();
        $quantityKg = round($quantityKg, 3);
        if ($quantityKg <= 0) {
            throw new RuntimeException('مقدار ضایعات باید بیشتر از صفر باشد.');
        }
        if ($material->isPieceUnit() && ! ProductQuantityTools::isWholeNumber($quantityKg)) {
            throw new RuntimeException('تعداد ضایعات باید عدد صحیح باشد.');
        }

        return DB::transaction(function () use ($material, $quantityKg, $note, $wastedAt) {
            $plan = $this->fifo->plan($material, $quantityKg, true);
            if ($plan['shortage_kg'] > 0) {
                $unit = $material->isPieceUnit() ? 'عدد' : 'کیلو';
                throw new RuntimeException(
                    'موجودی «'.$material->name.'» کافی نیست. کمبود: '.$plan['shortage_kg'].' '.$unit.'.'
                );
            }

            $waste = InventoryWaste::create([
                'atelier_id' => $material->atelier_id,
                'kind' => InventoryWaste::KIND_RAW,
                'raw_material_id' => $material->id,
                'produced_good_id' => null,
                'quantity_kg' => $quantityKg,
                'total_cost' => 0,
                'note' => $note,
                'wasted_at' => $wastedAt ?: now(),
            ]);

            $totalCost = 0.0;
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

                $waste->consumptions()->create([
                    'raw_material_lot_id' => $lot->id,
                    'production_id' => null,
                    'quantity_kg' => $take,
                    'price_per_kg' => $slice['price_per_kg'],
                    'cost' => $slice['cost'],
                ]);
                $totalCost += $slice['cost'];
            }

            $waste->total_cost = round($totalCost, 2);
            $waste->save();

            AccountingInventoryWastePoster::post($waste);

            return $waste->load(['consumptions.lot', 'rawMaterial']);
        });
    }

    public function wasteProducedGood(ProducedGood $good, float $quantityKg, ?string $note = null, $wastedAt = null): InventoryWaste
    {
        $this->assertTablesReady();
        $quantityKg = round($quantityKg, 3);
        if ($quantityKg <= 0) {
            throw new RuntimeException('مقدار ضایعات باید بیشتر از صفر باشد.');
        }
        if ($good->isPieceUnit() && ! ProductQuantityTools::isWholeNumber($quantityKg)) {
            throw new RuntimeException('تعداد ضایعات باید عدد صحیح باشد.');
        }

        return DB::transaction(function () use ($good, $quantityKg, $note, $wastedAt) {
            $plan = $this->fifo->planProducedGood($good, $quantityKg, true);
            if ($plan['shortage_kg'] > 0) {
                $unit = $good->isPieceUnit() ? 'عدد' : 'کیلو';
                throw new RuntimeException(
                    'موجودی «'.$good->name.'» کافی نیست. کمبود: '.$plan['shortage_kg'].' '.$unit.'.'
                );
            }

            $waste = InventoryWaste::create([
                'atelier_id' => $good->atelier_id,
                'kind' => InventoryWaste::KIND_PRODUCED,
                'raw_material_id' => null,
                'produced_good_id' => $good->id,
                'quantity_kg' => $quantityKg,
                'total_cost' => 0,
                'note' => $note,
                'wasted_at' => $wastedAt ?: now(),
            ]);

            $totalCost = 0.0;
            foreach ($plan['slices'] as $slice) {
                /** @var Production $source */
                $source = $slice['production'];
                $take = $slice['quantity_kg'];
                $left = round((float) $source->remaining_kg - $take, 3);
                if ($left < 0) {
                    throw new RuntimeException('موجودی تولید همزمان تغییر کرده است. دوباره تلاش کنید.');
                }
                $source->remaining_kg = $left;
                $source->save();

                $waste->consumptions()->create([
                    'raw_material_lot_id' => null,
                    'production_id' => $source->id,
                    'quantity_kg' => $take,
                    'price_per_kg' => $slice['price_per_kg'],
                    'cost' => $slice['cost'],
                ]);
                $totalCost += $slice['cost'];
            }

            $waste->total_cost = round($totalCost, 2);
            $waste->save();

            AccountingInventoryWastePoster::post($waste);

            return $waste->load(['consumptions.production', 'producedGood']);
        });
    }

    public function reverse(InventoryWaste $waste): void
    {
        $this->assertTablesReady();

        DB::transaction(function () use ($waste) {
            /** @var InventoryWaste|null $locked */
            $locked = InventoryWaste::query()->where('id', $waste->id)->lockForUpdate()->first();
            if (! $locked) {
                throw new RuntimeException('ضایعات یافت نشد.');
            }

            $locked->load('consumptions');
            AccountingInventoryWastePoster::reverse($locked);

            $lotIds = $locked->consumptions->pluck('raw_material_lot_id')->unique()->filter()->values();
            $lots = RawMaterialLot::query()
                ->whereIn('id', $lotIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $productionIds = $locked->consumptions->pluck('production_id')->unique()->filter()->values();
            $productions = Production::query()
                ->whereIn('id', $productionIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($locked->consumptions as $row) {
                $productionId = (int) ($row->production_id ?? 0);
                if ($productionId > 0) {
                    $production = $productions->get($productionId);
                    if (! $production) {
                        throw new RuntimeException('بچ تولید ضایع‌شده یافت نشد.');
                    }
                    $production->remaining_kg = round((float) $production->remaining_kg + (float) $row->quantity_kg, 3);
                    $production->save();

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

    private function assertTablesReady(): void
    {
        if (! Schema::hasTable('inventory_wastes') || ! Schema::hasTable('inventory_waste_consumptions')) {
            throw new RuntimeException(
                'جدول ضایعات وجود ندارد. migration یا SQL مربوط به inventory_wastes را اجرا کنید.'
            );
        }
    }
}
