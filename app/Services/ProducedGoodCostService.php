<?php

namespace App\Services;

use App\Models\ProducedGood;
use App\Models\RawMaterial;
use Illuminate\Support\Facades\Schema;

class ProducedGoodCostService
{
    /** @var RawMaterialFifoService */
    private $fifo;

    public function __construct(RawMaterialFifoService $fifo)
    {
        $this->fifo = $fifo;
    }

    public function attachCost(ProducedGood $good, float $quantityKg = 1.0, bool $preferInventoryCost = false): ProducedGood
    {
        return $this->fifo->attachFifoCost($good, $quantityKg, $preferInventoryCost);
    }

    /**
     * @return array<int, string>
     */
    private function ingredientWith(): array
    {
        $with = ['ingredients.rawMaterial'];
        if (Schema::hasColumn('produced_good_ingredients', 'ingredient_produced_good_id')) {
            $with[] = 'ingredients.ingredientProducedGood';
        }

        return $with;
    }

    public function goodsUsingMaterial(RawMaterial $material)
    {
        return ProducedGood::query()
            ->where('atelier_id', $material->atelier_id)
            ->whereHas('ingredients', function ($q) use ($material) {
                $q->where('raw_material_id', $material->id);
            })
            ->with($this->ingredientWith())
            ->orderBy('name')
            ->get()
            ->each(function (ProducedGood $good) {
                $this->attachCost($good);
            });
    }

    public function goodsUsingProducedGood(ProducedGood $ingredient)
    {
        if (! Schema::hasColumn('produced_good_ingredients', 'ingredient_produced_good_id')) {
            return collect();
        }

        return ProducedGood::query()
            ->where('atelier_id', $ingredient->atelier_id)
            ->whereHas('ingredients', function ($q) use ($ingredient) {
                $q->where('ingredient_produced_good_id', $ingredient->id);
            })
            ->with($this->ingredientWith())
            ->orderBy('name')
            ->get()
            ->each(function (ProducedGood $good) {
                $this->attachCost($good);
            });
    }
}
