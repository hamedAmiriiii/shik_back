<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ProducedGood;
use App\Models\Production;
use App\Models\RawMaterial;
use App\Services\ProducedGoodCostService;
use App\Services\RawMaterialFifoService;
use App\Tools\PriceTools;
use App\Tools\ProductQuantityTools;
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

        $atelierId = $this->assertShopFeature($request, \App\Services\ShopFeatureFlags::PRODUCED_GOODS, 'کالای تولیدی برای این فروشگاه فعال نیست.');

        $with = ['ingredients.rawMaterial'];
        if (Schema::hasTable('category_produced_good')) {
            $with[] = 'categories';
        }

        $query = ProducedGood::query()
            ->where('atelier_id', $atelierId)
            ->with($with);
        if (Schema::hasColumn('produced_goods', 'display_order')) {
            $query->orderBy('display_order')->orderBy('name');
        } else {
            $query->orderBy('name');
        }

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
        $pricing = $this->pricingFromFields($fields, null, $atelierId);

        $unitType = $fields['unit_type'] ?? ProducedGood::UNIT_KG;
        $hasUnitColumn = Schema::hasColumn('produced_goods', 'unit_type');
        if ($unitType === ProducedGood::UNIT_PIECE && ! $hasUnitColumn) {
            return response()->json([
                'message' => 'ستون unit_type در جدول produced_goods وجود ندارد. migration یا SQL مربوط را اجرا کنید.',
            ], 503);
        }

        $good = DB::transaction(function () use ($atelierId, $fields, $pricing, $unitType, $hasUnitColumn) {
            $payload = [
                'atelier_id' => $atelierId,
                'name' => $fields['name'],
                'display_order' => $this->normalizedDisplayOrder($fields['display_order'] ?? null, true),
                'sale_price' => $pricing['sale_price'],
                'markup_percent' => $pricing['markup_percent'],
                'round_sale_price' => $this->boolField($fields, 'round_sale_price', false),
                'note' => $fields['note'] ?? null,
            ];
            if ($hasUnitColumn) {
                $payload['unit_type'] = $unitType;
            }
            $good = ProducedGood::create($payload);
            $this->syncIngredients($good, $fields['ingredients'] ?? []);
            if (array_key_exists('layer_order', $fields)) {
                $this->syncLayerOrder($good, $fields['layer_order']);
            }
            $this->syncCategories($good, $fields['category_ids'] ?? [], $atelierId);

            return $good;
        });

        $good = $costService->attachCost($this->loadProducedGoodRelations($good));
        $good->syncSalePriceFromCost((float) $good->cost_per_kg);

        return response($costService->attachCost($good), 201);
    }

    public function show(Request $request, ProducedGood $producedGood, ProducedGoodCostService $costService)
    {
        $atelierId = $this->assertShopFeature($request, \App\Services\ShopFeatureFlags::PRODUCED_GOODS, 'کالای تولیدی برای این فروشگاه فعال نیست.');
        if ((int) $producedGood->atelier_id !== $atelierId) {
            return response(['message' => 'یافت نشد'], 404);
        }

        $quantityKg = (float) $request->input('quantity_kg', 1);

        return response(
            $costService->attachCost($this->loadProducedGoodRelations($producedGood), $quantityKg > 0 ? $quantityKg : 1),
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

        if (array_key_exists('unit_type', $fields)) {
            if (! Schema::hasColumn('produced_goods', 'unit_type')) {
                unset($fields['unit_type']);
            } elseif ($fields['unit_type'] !== $producedGood->unitType() && $producedGood->productions()->exists()) {
                return response()->json([
                    'message' => 'بعد از ثبت تولید، واحد کالا قابل تغییر نیست.',
                ], 422);
            }
        }

        DB::transaction(function () use ($producedGood, $fields) {
            $payload = [];
            if (array_key_exists('unit_type', $fields)) {
                $payload['unit_type'] = $fields['unit_type'];
            }
            if (array_key_exists('name', $fields)) {
                $payload['name'] = $fields['name'];
            }
            if (array_key_exists('note', $fields)) {
                $payload['note'] = $fields['note'];
            }
            if (array_key_exists('display_order', $fields)) {
                $payload['display_order'] = $this->normalizedDisplayOrder($fields['display_order'], false);
            }
            if (array_key_exists('round_sale_price', $fields)) {
                $payload['round_sale_price'] = $this->boolField($fields, 'round_sale_price', false);
            }
            if (array_key_exists('markup_percent', $fields) || array_key_exists('sale_price', $fields)) {
                $pricing = $this->pricingFromFields($fields, $producedGood, (int) $producedGood->atelier_id);
                $payload['sale_price'] = $pricing['sale_price'];
                $payload['markup_percent'] = $pricing['markup_percent'];
            }
            if ($payload) {
                $producedGood->update($payload);
            }

            if (array_key_exists('ingredients', $fields)) {
                $this->syncIngredients($producedGood, $fields['ingredients']);
            }
            if (array_key_exists('layer_order', $fields) || array_key_exists('ingredients', $fields)) {
                // با تغییر فرمول، لایه‌های موادِ حذف‌شده هم پاک می‌شوند.
                $this->syncLayerOrder(
                    $producedGood,
                    array_key_exists('layer_order', $fields) ? $fields['layer_order'] : ($producedGood->layer_order ?? [])
                );
            }
            if (array_key_exists('category_ids', $fields)) {
                $this->syncCategories($producedGood, $fields['category_ids'] ?? [], (int) $producedGood->atelier_id);
            }
        });

        $good = $costService->attachCost($this->loadProducedGoodRelations($producedGood->fresh()));
        if (array_key_exists('markup_percent', $fields) && $good->usesMarkup()) {
            $good->syncSalePriceFromCost((float) $good->cost_per_kg);
            $good = $costService->attachCost($good);
        } elseif (array_key_exists('round_sale_price', $fields) || array_key_exists('sale_price', $fields)) {
            if ($good->syncRoundedSalePrice()) {
                $good = $costService->attachCost($good);
            }
        }

        return response($good, 200);
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

        if ($producedGood->isPieceUnit() && ! ProductQuantityTools::isWholeNumber($fields['quantity_kg'])) {
            return response()->json([
                'message' => 'تعداد تولید باید عدد صحیح باشد.',
            ], 422);
        }

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
        $atelierId = $this->assertShopFeature($request, \App\Services\ShopFeatureFlags::PRODUCED_GOODS, 'کالای تولیدی برای این فروشگاه فعال نیست.');
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
            'unit_type' => $production->producedGood ? $production->producedGood->unitType() : ProducedGood::UNIT_KG,
            'quantity_kg' => (float) $production->quantity_kg,
            'remaining_kg' => (float) $production->remaining_kg,
            'sold_kg' => round((float) $production->quantity_kg - (float) $production->remaining_kg, 3),
            'can_reverse' => round((float) $production->remaining_kg, 3) === round((float) $production->quantity_kg, 3),
            'total_cost' => (float) $production->total_cost,
            'cost_per_kg' => (float) $production->cost_per_kg,
            'sale_price' => (float) optional($production->producedGood)->sale_price,
            'markup_percent' => optional($production->producedGood)->markup_percent !== null
                ? (float) $production->producedGood->markup_percent
                : null,
            'sale_price_mode' => optional($production->producedGood)->sale_price_mode,
            'round_sale_price' => (bool) optional($production->producedGood)->round_sale_price,
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
            'display_order' => 'nullable|integer|min:0|max:9999',
            'unit_type' => ['sometimes', Rule::in([ProducedGood::UNIT_KG, ProducedGood::UNIT_PIECE])],
            'sale_price' => 'nullable|numeric|min:0',
            'markup_percent' => 'nullable|numeric|min:0|max:9999',
            'round_sale_price' => 'sometimes|boolean',
            'note' => 'nullable|string',
            'ingredients' => $ingredientsRule,
            'ingredients.*.raw_material_id' => 'required|integer',
            'ingredients.*.grams_per_kg' => 'required|numeric|min:0.001',
            'ingredients.*.is_removable' => 'sometimes|boolean',
            'layer_order' => 'sometimes|nullable|array|max:40',
            'layer_order.*' => 'integer',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|exists:categories,id',
        ]);
    }

    /**
     * @param  mixed  $value
     */
    private function normalizedDisplayOrder($value, bool $forCreate): int
    {
        if (! Schema::hasColumn('produced_goods', 'display_order')) {
            return ProducedGood::DEFAULT_DISPLAY_ORDER;
        }

        if ($value === null || $value === '') {
            return ProducedGood::DEFAULT_DISPLAY_ORDER;
        }

        return max(0, min(9999, (int) $value));
    }

    /**
     * درصد پر باشد → حالت خودکار. فقط قیمت فروش → حالت دستی.
     *
     * @return array{sale_price: float, markup_percent: float|null}
     */
    private function pricingFromFields(array $fields, ?ProducedGood $existing = null, ?int $atelierId = null): array
    {
        $hasMarkup = array_key_exists('markup_percent', $fields);
        $hasSale = array_key_exists('sale_price', $fields);
        $currentSale = $existing ? (float) $existing->sale_price : 0.0;
        $currentMarkup = $existing ? $existing->markup_percent : null;
        $round = $this->boolField(
            $fields,
            'round_sale_price',
            $existing ? (bool) $existing->round_sale_price : false
        );

        if ($hasMarkup && $fields['markup_percent'] !== null && $fields['markup_percent'] !== '') {
            $sale = $hasSale ? round((float) $fields['sale_price'], 2) : $currentSale;

            return [
                'markup_percent' => round((float) $fields['markup_percent'], 2),
                'sale_price' => $round ? PriceTools::roundSalePrice($sale, $atelierId) : $sale,
            ];
        }

        if ($hasMarkup || $hasSale) {
            $sale = $hasSale ? round((float) $fields['sale_price'], 2) : $currentSale;

            return [
                'markup_percent' => null,
                'sale_price' => $round ? PriceTools::roundSalePrice($sale, $atelierId) : $sale,
            ];
        }

        return [
            'markup_percent' => $currentMarkup,
            'sale_price' => $round ? PriceTools::roundSalePrice($currentSale, $atelierId) : $currentSale,
        ];
    }

    private function boolField(array $fields, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $fields)) {
            return $default;
        }

        $value = $fields[$key];
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
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

    private function loadProducedGoodRelations(ProducedGood $good): ProducedGood
    {
        $with = ['ingredients.rawMaterial'];
        if (Schema::hasTable('category_produced_good')) {
            $with[] = 'categories';
        }

        return $good->load($with);
    }

    private function syncIngredients(ProducedGood $good, array $ingredients): void
    {
        $good->ingredients()->delete();

        $hasRemovable = Schema::hasColumn('produced_good_ingredients', 'is_removable');

        foreach ($ingredients as $row) {
            $payload = [
                'raw_material_id' => $row['raw_material_id'],
                'grams_per_kg' => $row['grams_per_kg'],
            ];
            if ($hasRemovable) {
                $payload['is_removable'] = ! empty($row['is_removable']);
            }
            $good->ingredients()->create($payload);
        }
    }

    /**
     * ترتیب لایه‌های عکس (بالا به پایین). هر ماده می‌تواند چند بار بیاید؛ فقط مواد فرمول مجازند.
     *
     * @param  array<int, int|string>|null  $layerOrder
     */
    private function syncLayerOrder(ProducedGood $good, ?array $layerOrder): void
    {
        if (! Schema::hasColumn('produced_goods', 'layer_order')) {
            return;
        }

        $allowed = $good->ingredients()->pluck('raw_material_id')->map(function ($id) {
            return (int) $id;
        })->all();

        $clean = [];
        foreach ((array) $layerOrder as $id) {
            $id = (int) $id;
            if (in_array($id, $allowed, true)) {
                $clean[] = $id;
            }
        }

        $good->layer_order = $clean === [] ? null : $clean;
        $good->save();
    }

    private function syncCategories(ProducedGood $good, array $categoryIds, int $atelierId): void
    {
        if (! Schema::hasTable('category_produced_good')) {
            return;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));
        if ($ids === []) {
            $good->categories()->sync([]);

            return;
        }

        $valid = Category::query()
            ->where('atelier_id', $atelierId)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        if (count($valid) !== count($ids)) {
            abort(response()->json([
                'message' => 'یک یا چند دسته متعلق به این فروشگاه نیست.',
            ], 422));
        }

        $good->categories()->sync($valid);
    }
}
