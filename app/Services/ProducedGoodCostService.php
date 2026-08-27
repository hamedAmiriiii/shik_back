<?php

namespace App\Services;

use App\Models\ProducedGood;
use App\Models\RawMaterial;

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

    public function goodsUsingMaterial(RawMaterial $material)
    {
        return ProducedGood::query()
            ->where('atelier_id', $material->atelier_id)
            ->whereHas('ingredients', function ($q) use ($material) {
                $q->where('raw_material_id', $material->id);
            })
            ->with('ingredients.rawMaterial')
            ->orderBy('name')
            ->get()
            ->each(function (ProducedGood $good) {
                $this->attachCost($good);
            });
    }
}
