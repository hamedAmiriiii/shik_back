<?php

namespace App\Http\Controllers;

use App\Models\InventoryWaste;
use App\Models\ProducedGood;
use App\Models\RawMaterial;
use App\Services\InventoryWasteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use RuntimeException;

class InventoryWasteController extends Controller
{
    public function index(Request $request)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            \App\Services\ShopFeatureFlags::PRODUCED_GOODS,
            'کالای تولیدی برای این فروشگاه فعال نیست.'
        );

        if (! Schema::hasTable('inventory_wastes')) {
            return response()->json([
                'message' => 'جدول inventory_wastes وجود ندارد. migration یا SQL را اجرا کنید.',
            ], 503);
        }

        $query = InventoryWaste::query()
            ->where('atelier_id', $atelierId)
            ->with(['rawMaterial', 'producedGood', 'consumptions.lot', 'consumptions.production'])
            ->orderByDesc('wasted_at')
            ->orderByDesc('id');

        if ($request->filled('kind')) {
            $kind = (string) $request->input('kind');
            if (in_array($kind, InventoryWaste::kinds(), true)) {
                $query->where('kind', $kind);
            }
        }
        if ($request->filled('raw_material_id')) {
            $query->where('raw_material_id', (int) $request->input('raw_material_id'));
        }
        if ($request->filled('produced_good_id')) {
            $query->where('produced_good_id', (int) $request->input('produced_good_id'));
        }
        if ($request->filled('from_date')) {
            $query->whereDate('wasted_at', '>=', $request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('wasted_at', '<=', $request->input('to_date'));
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('note', 'like', '%'.$search.'%')
                        ->orWhereHas('rawMaterial', function ($m) use ($search) {
                            $m->where('name', 'like', '%'.$search.'%');
                        })
                        ->orWhereHas('producedGood', function ($g) use ($search) {
                            $g->where('name', 'like', '%'.$search.'%');
                        });
                });
            }
        }

        $summaryQuery = clone $query;
        $summary = [
            'count' => (clone $summaryQuery)->count(),
            'total_cost' => round((float) (clone $summaryQuery)->sum('total_cost'), 2),
            'total_quantity' => round((float) (clone $summaryQuery)->sum('quantity_kg'), 3),
            'raw_material_cost' => round((float) (clone $summaryQuery)->where('kind', InventoryWaste::KIND_RAW)->sum('total_cost'), 2),
            'produced_good_cost' => round((float) (clone $summaryQuery)->where('kind', InventoryWaste::KIND_PRODUCED)->sum('total_cost'), 2),
        ];

        $perPage = (int) $request->input('per_page', 30);
        $perPage = $perPage > 0 ? min($perPage, 100) : 30;

        if ($request->boolean('paginate', true)) {
            $page = $query->paginate($perPage);
            $page->getCollection()->transform(function (InventoryWaste $waste) {
                return $this->serialize($waste);
            });

            return response([
                'summary' => $summary,
                'data' => $page->items(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ], 200);
        }

        $rows = $query->limit(500)->get()->map(function (InventoryWaste $waste) {
            return $this->serialize($waste);
        });

        return response([
            'summary' => $summary,
            'data' => $rows,
        ], 200);
    }

    public function store(Request $request, InventoryWasteService $wasteService)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json([
                'message' => 'ثبت ضایعات فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422);
        }

        if (! Schema::hasTable('inventory_wastes')) {
            return response()->json([
                'message' => 'جدول inventory_wastes وجود ندارد. migration یا SQL را اجرا کنید.',
            ], 503);
        }

        $fields = $request->validate([
            'kind' => ['required', Rule::in(InventoryWaste::kinds())],
            'raw_material_id' => 'nullable|integer',
            'produced_good_id' => 'nullable|integer',
            'quantity_kg' => 'required|numeric|min:0.001',
            'note' => 'nullable|string|max:2000',
            'wasted_at' => 'nullable|date',
        ]);

        try {
            if ($fields['kind'] === InventoryWaste::KIND_RAW) {
                $materialId = (int) ($fields['raw_material_id'] ?? 0);
                if ($materialId <= 0) {
                    return response()->json(['message' => 'ماده اولیه را انتخاب کنید.'], 422);
                }
                $material = RawMaterial::query()
                    ->where('atelier_id', $atelierId)
                    ->where('id', $materialId)
                    ->first();
                if (! $material) {
                    return response()->json(['message' => 'ماده اولیه یافت نشد.'], 404);
                }
                $waste = $wasteService->wasteRawMaterial(
                    $material,
                    (float) $fields['quantity_kg'],
                    $fields['note'] ?? null,
                    $fields['wasted_at'] ?? null
                );
            } else {
                $goodId = (int) ($fields['produced_good_id'] ?? 0);
                if ($goodId <= 0) {
                    return response()->json(['message' => 'کالای تولیدی را انتخاب کنید.'], 422);
                }
                $good = ProducedGood::query()
                    ->where('atelier_id', $atelierId)
                    ->where('id', $goodId)
                    ->first();
                if (! $good) {
                    return response()->json(['message' => 'کالای تولیدی یافت نشد.'], 404);
                }
                $waste = $wasteService->wasteProducedGood(
                    $good,
                    (float) $fields['quantity_kg'],
                    $fields['note'] ?? null,
                    $fields['wasted_at'] ?? null
                );
            }
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response($this->serialize($waste), 201);
    }

    public function destroy(Request $request, InventoryWaste $inventoryWaste, InventoryWasteService $wasteService)
    {
        $this->assertModelBelongsToStaffAtelier($request, $inventoryWaste);

        try {
            $wasteService->reverse($inventoryWaste);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response(['message' => 'ضایعات برگشت خورد و موجودی بازگردانده شد.'], 200);
    }

    private function serialize(InventoryWaste $waste): array
    {
        $waste->loadMissing(['rawMaterial', 'producedGood', 'consumptions.lot', 'consumptions.production']);

        $itemName = $waste->isRawMaterial()
            ? optional($waste->rawMaterial)->name
            : optional($waste->producedGood)->name;

        $unitType = $waste->isRawMaterial()
            ? ($waste->rawMaterial ? $waste->rawMaterial->unitType() : RawMaterial::UNIT_KG)
            : ($waste->producedGood ? $waste->producedGood->unitType() : ProducedGood::UNIT_KG);

        return [
            'id' => $waste->id,
            'kind' => $waste->kind,
            'raw_material_id' => $waste->raw_material_id,
            'produced_good_id' => $waste->produced_good_id,
            'item_name' => $itemName,
            'unit_type' => $unitType,
            'quantity_kg' => (float) $waste->quantity_kg,
            'total_cost' => (float) $waste->total_cost,
            'cost_per_kg' => (float) $waste->quantity_kg > 0
                ? round((float) $waste->total_cost / (float) $waste->quantity_kg, 2)
                : 0,
            'note' => $waste->note,
            'wasted_at' => optional($waste->wasted_at)->toDateTimeString(),
            'created_at' => optional($waste->created_at)->toDateTimeString(),
            'consumptions' => $waste->consumptions->map(function ($row) use ($waste) {
                return [
                    'id' => $row->id,
                    'raw_material_lot_id' => $row->raw_material_lot_id,
                    'production_id' => $row->production_id,
                    'source_label' => $waste->isRawMaterial()
                        ? ('لات '.($row->raw_material_lot_id ?: '—'))
                        : ('تولید #'.($row->production_id ?: '—')),
                    'quantity_kg' => (float) $row->quantity_kg,
                    'price_per_kg' => (float) $row->price_per_kg,
                    'cost' => (float) $row->cost,
                ];
            })->values(),
        ];
    }
}
