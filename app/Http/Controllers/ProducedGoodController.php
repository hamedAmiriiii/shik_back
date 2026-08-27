<?php

namespace App\Http\Controllers;

use App\Models\ProducedGood;
use App\Models\Production;
use App\Models\RawMaterial;
use App\Services\ProducedGoodCostService;
use App\Services\RawMaterialFifoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use RuntimeException;

class ProducedGoodController extends Controller
{
    public function index(Request $request, ProducedGoodCostService $costService)
    {
        if (! Schema::hasTable('produced_goods')) {
            return response()->json([
                'message' => 'جدول produced_goods وجود ندارد. migration یا SQL را اجرا کنید.',
            ], 503);
        }

        $atelierId = $this->shopAtelierIdOrAbort($request);

        $query = ProducedGood::query()
            ->where('atelier_id', $atelierId)
            ->with('ingredients.rawMaterial')
            ->orderBy('name');

        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel) {
            $query->where(function ($q) use ($searchDataModel) {
                if (is_object($searchDataModel) && isset($searchDataModel->name)) {
                    $q->where('name', 'like', '%'.$searchDataModel->name.'%');
                } elseif (is_string($searchDataModel) && $searchDataModel !== '') {
                    $q->where('name', 'like', '%'.$searchDataModel.'%');
                }
            });
        } elseif ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->input('search').'%');
        }

        $attach = function ($goods) use ($costService) {
            return $goods->each(function (ProducedGood $good) use ($costService) {
                $costService->attachCost($good, 1.0, true);
            });
        };

        if ($request->boolean('paginate')) {
            $perPage = (int) $request->input('per_page', 20);
            $perPage = $perPage > 0 ? min($perPage, 100) : 20;
            $page = $query->paginate($perPage);
            $attach($page->getCollection());

            return response($page, 200);
        }

        return response($attach($query->get()), 200);
    }

    public function store(Request $request, ProducedGoodCostService $costService)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            return response()->json([
                'message' => 'ثبت کالا فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422);
        }

        $fields = $this->validatedGood($request, $atelierId);
        $this->assertIngredientsBelongToShop($fields['ingredients'] ?? [], $atelierId);

        $good = DB::transaction(function () use ($atelierId, $fields) {
            $good = ProducedGood::create([
                'atelier_id' => $atelierId,
                'name' => $fields['name'],
                'sale_price' => $fields['sale_price'] ?? 0,
                'note' => $fields['note'] ?? null,
            ]);
            $this->syncIngredients($good, $fields['ingredients'] ?? []);

            return $good;
        });

        return response($costService->attachCost($good->load('ingredients.rawMaterial')), 201);
    }

    public function show(Request $request, ProducedGood $producedGood, ProducedGoodCostService $costService)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if ((int) $producedGood->atelier_id !== $atelierId) {
            return response(['message' => 'یافت نشد'], 404);
        }

        $quantityKg = (float) $request->input('quantity_kg', 1);

        return response(
            $costService->attachCost($producedGood->load('ingredients.rawMaterial'), $quantityKg > 0 ? $quantityKg : 1),
            200
        );
    }

    public function update(Request $request, ProducedGood $producedGood, ProducedGoodCostService $costService)
    {
        $this->assertModelBelongsToStaffAtelier($request, $producedGood);

        $atelierId = (int) $producedGood->atelier_id;
        $fields = $this->validatedGood($request, $atelierId, $producedGood->id);

        if (isset($fields['ingredients'])) {
            $this->assertIngredientsBelongToShop($fields['ingredients'], $atelierId);
        }

        DB::transaction(function () use ($producedGood, $fields) {
            $payload = [];
            if (array_key_exists('name', $fields)) {
                $payload['name'] = $fields['name'];
            }
            if (array_key_exists('sale_price', $fields)) {
                $payload['sale_price'] = $fields['sale_price'];
            }
            if (array_key_exists('note', $fields)) {
                $payload['note'] = $fields['note'];
            }
            if ($payload) {
                $producedGood->update($payload);
            }

            if (array_key_exists('ingredients', $fields)) {
                $this->syncIngredients($producedGood, $fields['ingredients']);
            }
        });

        return response(
            $costService->attachCost($producedGood->fresh()->load('ingredients.rawMaterial')),
            200
        );
    }

    public function destroy(Request $request, ProducedGood $producedGood)
    {
        $this->assertModelBelongsToStaffAtelier($request, $producedGood);

        if ($producedGood->productions()->exists()) {
            return response()->json([
                'message' => 'برای این کالا تولید ثبت شده و قابل حذف نیست.',
            ], 422);
        }

        $producedGood->delete();

        return response(['message' => 'کالای تولیدی حذف شد'], 200);
    }

    public function produce(Request $request, ProducedGood $producedGood, RawMaterialFifoService $fifo)
    {
        $this->assertModelBelongsToStaffAtelier($request, $producedGood);

        $fields = $request->validate([
            'quantity_kg' => 'required|numeric|min:0.001',
            'note' => 'nullable|string',
        ]);

        try {
            $production = $fifo->produce(
                $producedGood,
                (float) $fields['quantity_kg'],
                $fields['note'] ?? null
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response($this->serializeProduction($production), 201);
    }

    public function destroyProduction(Request $request, ProducedGood $producedGood, Production $production, RawMaterialFifoService $fifo)
    {
        $this->assertModelBelongsToStaffAtelier($request, $producedGood);
        if ((int) $production->produced_good_id !== (int) $producedGood->id
            || (int) $production->atelier_id !== (int) $producedGood->atelier_id) {
            return response(['message' => 'یافت نشد'], 404);
        }

        try {
            $fifo->reverseProduction($production);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response([
            'message' => 'تولید برگشت خورد و موجودی مواد اولیه بازگردانده شد.',
        ], 200);
    }

    public function productions(Request $request, ProducedGood $producedGood)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if ((int) $producedGood->atelier_id !== $atelierId) {
            return response(['message' => 'یافت نشد'], 404);
        }

        $query = Production::query()
            ->where('produced_good_id', $producedGood->id)
            ->where('atelier_id', $atelierId)
            ->with(['consumptions.rawMaterial'])
            ->orderByDesc('id');

        $perPage = (int) $request->input('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;
        $page = $query->paginate($perPage);
        $page->getCollection()->transform(function (Production $production) {
            return $this->serializeProduction($production);
        });

        return response($page, 200);
    }

    private function serializeProduction(Production $production): array
    {
        $production->loadMissing(['consumptions.rawMaterial', 'producedGood']);

        return [
            'id' => $production->id,
            'produced_good_id' => $production->produced_good_id,
            'produced_good_name' => optional($production->producedGood)->name,
            'quantity_kg' => (float) $production->quantity_kg,
            'remaining_kg' => (float) $production->remaining_kg,
            'sold_kg' => round((float) $production->quantity_kg - (float) $production->remaining_kg, 3),
            'can_reverse' => round((float) $production->remaining_kg, 3) === round((float) $production->quantity_kg, 3),
            'total_cost' => (float) $production->total_cost,
            'cost_per_kg' => (float) $production->cost_per_kg,
            'note' => $production->note,
            'created_at' => optional($production->created_at)->toDateTimeString(),
            'consumptions' => $production->consumptions->map(function ($row) {
                return [
                    'id' => $row->id,
                    'raw_material_id' => $row->raw_material_id,
                    'raw_material_name' => optional($row->rawMaterial)->name,
                    'raw_material_lot_id' => $row->raw_material_lot_id,
                    'quantity_kg' => (float) $row->quantity_kg,
                    'price_per_kg' => (float) $row->price_per_kg,
                    'cost' => (float) $row->cost,
                ];
            })->values(),
        ];
    }

    private function validatedGood(Request $request, int $atelierId, ?int $ignoreId = null): array
    {
        $nameUnique = Rule::unique('produced_goods', 'name')->where('atelier_id', $atelierId);
        if ($ignoreId) {
            $nameUnique = $nameUnique->ignore($ignoreId);
        }

        $nameRule = $ignoreId
            ? ['sometimes', 'required', 'string', 'max:255', $nameUnique]
            : ['required', 'string', 'max:255', $nameUnique];

        $ingredientsRule = $ignoreId ? 'sometimes|array' : 'nullable|array';

        return $request->validate([
            'name' => $nameRule,
            'sale_price' => 'nullable|numeric|min:0',
            'note' => 'nullable|string',
            'ingredients' => $ingredientsRule,
            'ingredients.*.raw_material_id' => 'required|integer',
            'ingredients.*.grams_per_kg' => 'required|numeric|min:0.001',
        ]);
    }

    private function assertIngredientsBelongToShop(array $ingredients, int $atelierId): void
    {
        $ids = array_unique(array_map(function ($row) {
            return (int) $row['raw_material_id'];
        }, $ingredients));

        if (count($ids) === 0) {
            return;
        }

        if (count($ids) !== count($ingredients)) {
            abort(response()->json([
                'message' => 'هر ماده اولیه فقط یک‌بار در فرمول کالا می‌تواند بیاید.',
            ], 422));
        }

        $found = RawMaterial::where('atelier_id', $atelierId)
            ->whereIn('id', $ids)
            ->count();

        if ($found !== count($ids)) {
            abort(response()->json([
                'message' => 'یک یا چند ماده اولیه متعلق به این فروشگاه نیست.',
            ], 422));
        }
    }

    private function syncIngredients(ProducedGood $good, array $ingredients): void
    {
        $good->ingredients()->delete();

        foreach ($ingredients as $row) {
            $good->ingredients()->create([
                'raw_material_id' => $row['raw_material_id'],
                'grams_per_kg' => $row['grams_per_kg'],
            ]);
        }
    }
}
