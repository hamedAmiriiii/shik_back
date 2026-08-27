<?php

namespace App\Http\Controllers;

use App\Models\ProducedGood;
use App\Models\RawMaterial;
use App\Services\ProducedGoodCostService;
use App\Services\RawMaterialFifoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class PosCatalogController extends Controller
{
    /**
     * کاتالوگ فروش POS از کالاهای تولیدی و مواد اولیه (نه جدول products).
     */
    public function index(Request $request, ProducedGoodCostService $costService, RawMaterialFifoService $fifo)
    {
        if (! Schema::hasTable('produced_goods') || ! Schema::hasTable('raw_materials')) {
            return response()->json([
                'message' => 'جداول تولید/مواد اولیه وجود ندارد. SQL را اجرا کنید.',
            ], 503);
        }

        $atelierId = $this->shopAtelierIdOrAbort($request);
        $type = $request->input('type');

        $goods = collect();
        $materials = collect();

        if ($type !== 'raw_material') {
            $goods = ProducedGood::query()
                ->where('atelier_id', $atelierId)
                ->with('ingredients.rawMaterial')
                ->orderBy('name')
                ->get()
                ->each(function (ProducedGood $good) use ($costService) {
                    $costService->attachCost($good, 1.0, true);
                })
                ->map(function (ProducedGood $good) {
                    return [
                        'id' => $good->id,
                        'item_type' => 'produced_good',
                        'produced_good_id' => $good->id,
                        'name' => $good->name,
                        'sale_price' => (float) $good->sale_price,
                        'cost_per_kg' => (float) $good->cost_per_kg,
                        'purchase_price' => (float) $good->cost_per_kg,
                        'profit_per_kg' => (float) $good->profit_per_kg,
                        'stock_kg' => (float) $good->stock_kg,
                        'unit_type' => 'kg',
                        'stock_sufficient' => (bool) $good->stock_sufficient,
                    ];
                })
                ->values();
        }

        if ($type !== 'produced_good') {
            $materials = RawMaterial::query()
                ->where('atelier_id', $atelierId)
                ->with('openLots')
                ->orderBy('name')
                ->get()
                ->each(function (RawMaterial $material) use ($fifo) {
                    $fifo->attachStock($material);
                })
                ->map(function (RawMaterial $material) {
                    return [
                        'id' => $material->id,
                        'item_type' => 'raw_material',
                        'raw_material_id' => $material->id,
                        'name' => $material->name,
                        'sale_price' => (float) $material->sale_price,
                        'purchase_price' => $material->next_price_per_kg,
                        'stock_kg' => (float) $material->stock_kg,
                        'unit_type' => 'kg',
                    ];
                })
                ->values();
        }

        return response()->json([
            'produced_goods' => $goods,
            'raw_materials' => $materials,
            'items' => $goods->concat($materials)->values(),
        ]);
    }
}
