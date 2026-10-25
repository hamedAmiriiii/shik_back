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

        $with = $this->ingredientWith();
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
        $this->assertIngredientsBelongToShop($fields['ingredients'] ?? [], $atelierId, null);
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
            if (ProducedGood::hasIsFinalColumn()) {
                $payload['is_final'] = $this->boolField($fields, 'is_final', true);
            }
            if (Schema::hasColumn('produced_goods', 'layer_compose')) {
                $payload['layer_compose'] = $this->normalizedLayerCompose($fields['layer_compose'] ?? null);
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
            $this->assertIngredientsBelongToShop($fields['ingredients'], $atelierId, $producedGood->id);
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
            if (array_key_exists('is_final', $fields) && ProducedGood::hasIsFinalColumn()) {
                $payload['is_final'] = $this->boolField($fields, 'is_final', true);
            }
            if (array_key_exists('layer_compose', $fields) && Schema::hasColumn('produced_goods', 'layer_compose')) {
                $payload['layer_compose'] = $this->normalizedLayerCompose($fields['layer_compose']);
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

        if (Schema::hasColumn('produced_good_ingredients', 'ingredient_produced_good_id')) {
            $usedIn = \App\Models\ProducedGoodIngredient::query()
                ->where('ingredient_produced_good_id', $producedGood->id)
                ->exists();
            if ($usedIn) {
                return response()->json([
                    'message' => 'این کالا در فرمول کالای تولیدی دیگری استفاده شده و قابل حذف نیست.',
                ], 422);
            }
        }

        $this->removeCoverImageFile($producedGood);
        $producedGood->delete();

        return response(['message' => 'کالای تولیدی حذف شد'], 200);
    }

    /**
     * عکس کلی کالا (بدون لایه‌های مواد) برای لیست / سبد / کارت منو.
     * POST /api/produced-goods/{producedGood}/cover-image
     */
    public function uploadCoverImage(Request $request, ProducedGood $producedGood, ProducedGoodCostService $costService)
    {
        $this->assertModelBelongsToStaffAtelier($request, $producedGood);

        if (! Schema::hasColumn('produced_goods', 'cover_image_path')) {
            return response()->json([
                'message' => 'ستون cover_image_path وجود ندارد. migration یا SQL مربوط را اجرا کنید.',
            ], 503);
        }

        $ext = 'jpeg';
        $content = null;

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $mime = (string) $file->getMimeType();
            if (! in_array($mime, ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'], true)) {
                return response()->json(['message' => 'فرمت عکس باید jpg، png یا webp باشد.'], 422);
            }
            $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpeg');
            $content = file_get_contents($file->getRealPath());
        } else {
            $raw = (string) $request->input('image_base64', $request->input('image', ''));
            if ($raw === '') {
                return response()->json(['message' => 'فایل عکس را ارسال کنید.'], 422);
            }
            if (strpos($raw, ',') !== false) {
                $header = strtolower(strstr($raw, ',', true) ?: '');
                $raw = substr($raw, strpos($raw, ',') + 1);
                if (strpos($header, 'png') !== false) {
                    $ext = 'png';
                } elseif (strpos($header, 'webp') !== false) {
                    $ext = 'webp';
                } elseif (strpos($header, 'jpeg') === false && strpos($header, 'jpg') === false) {
                    return response()->json(['message' => 'فرمت عکس باید jpg، png یا webp باشد.'], 422);
                }
            }
            $content = base64_decode($raw, true);
        }

        if ($content === false || $content === null || $content === '') {
            return response()->json(['message' => 'عکس نامعتبر است.'], 422);
        }
        if (strlen($content) > 5 * 1024 * 1024) {
            return response()->json(['message' => 'حجم عکس نباید بیشتر از ۵ مگابایت باشد.'], 422);
        }

        $this->removeCoverImageFile($producedGood);

        $path = \App\Tools\ImageTools::saveFile(
            "/produced-goods/{$producedGood->id}/cover_".time().".{$ext}",
            $content
        );
        $producedGood->update(['cover_image_path' => $path]);

        return response(
            $costService->attachCost($this->loadProducedGoodRelations($producedGood->fresh())),
            200
        );
    }

    /**
     * DELETE /api/produced-goods/{producedGood}/cover-image
     */
    public function deleteCoverImage(Request $request, ProducedGood $producedGood, ProducedGoodCostService $costService)
    {
        $this->assertModelBelongsToStaffAtelier($request, $producedGood);

        if (Schema::hasColumn('produced_goods', 'cover_image_path')) {
            $this->removeCoverImageFile($producedGood);
            $producedGood->update(['cover_image_path' => null]);
        }

        return response(
            $costService->attachCost($this->loadProducedGoodRelations($producedGood->fresh())),
            200
        );
    }

    private function removeCoverImageFile(ProducedGood $producedGood): void
    {
        $path = $producedGood->getAttributes()['cover_image_path'] ?? null;
        if ($path && \Illuminate\Support\Facades\Storage::exists('public/'.$path)) {
            \Illuminate\Support\Facades\Storage::delete('public/'.$path);
        }
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
            'message' => 'تولید برگشت خورد و موجودی مواد بازگردانده شد.',
        ], 200);
    }

    public function productions(Request $request, ProducedGood $producedGood)
    {
        $atelierId = $this->assertShopFeature($request, \App\Services\ShopFeatureFlags::PRODUCED_GOODS, 'کالای تولیدی برای این فروشگاه فعال نیست.');
        if ((int) $producedGood->atelier_id !== $atelierId) {
            return response(['message' => 'یافت نشد'], 404);
        }

        $consumptionWith = ['consumptions.rawMaterial'];
        if (Schema::hasColumn('production_consumptions', 'ingredient_produced_good_id')) {
            $consumptionWith[] = 'consumptions.ingredientProducedGood';
        }

        $query = Production::query()
            ->where('produced_good_id', $producedGood->id)
            ->where('atelier_id', $atelierId)
            ->with($consumptionWith)
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
        $with = ['consumptions.rawMaterial', 'producedGood'];
        if (Schema::hasColumn('production_consumptions', 'ingredient_produced_good_id')) {
            $with[] = 'consumptions.ingredientProducedGood';
        }
        $production->loadMissing($with);

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
                $nestedId = (int) ($row->ingredient_produced_good_id ?? 0);
                $isNested = $nestedId > 0;

                return [
                    'id' => $row->id,
                    'ingredient_kind' => $isNested ? 'produced_good' : 'raw_material',
                    'raw_material_id' => $row->raw_material_id,
                    'raw_material_name' => $isNested
                        ? optional($row->ingredientProducedGood)->name
                        : optional($row->rawMaterial)->name,
                    'ingredient_produced_good_id' => $isNested ? $nestedId : null,
                    'raw_material_lot_id' => $row->raw_material_lot_id,
                    'source_production_id' => $row->source_production_id ?? null,
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
            'is_final' => 'sometimes|boolean',
            'sale_price' => 'nullable|numeric|min:0',
            'markup_percent' => 'nullable|numeric|min:0|max:9999',
            'round_sale_price' => 'sometimes|boolean',
            'note' => 'nullable|string',
            'ingredients' => $ingredientsRule,
            'ingredients.*.raw_material_id' => 'nullable|integer',
            'ingredients.*.ingredient_produced_good_id' => 'nullable|integer',
            'ingredients.*.grams_per_kg' => 'required|numeric|min:0.001',
            'ingredients.*.is_removable' => 'sometimes|boolean',
            'ingredients.*.is_addable' => 'sometimes|boolean',
            'layer_order' => 'sometimes|nullable|array|max:40',
            // عدد ساده (قدیمی) یا { raw_material_id, scale_x, scale_y, gap }
            'layer_order.*' => 'nullable',
            'layer_order.*.raw_material_id' => 'sometimes|integer',
            'layer_order.*.scale' => 'sometimes|nullable|numeric|min:0.2|max:3',
            'layer_order.*.scale_x' => 'sometimes|nullable|numeric|min:0.2|max:3',
            'layer_order.*.scale_y' => 'sometimes|nullable|numeric|min:0.2|max:3',
            'layer_order.*.gap' => 'sometimes|nullable|numeric|min:-40|max:80',
            'layer_compose' => ['sometimes', 'nullable', Rule::in(ProducedGood::layerComposeModes())],
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|exists:categories,id',
        ]);
    }

    /**
     * @param  mixed  $value
     */
    private function normalizedLayerCompose($value): string
    {
        $mode = is_string($value) ? trim($value) : '';

        return in_array($mode, ProducedGood::layerComposeModes(), true)
            ? $mode
            : ProducedGood::LAYER_COMPOSE_SIDE;
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

    /**
     * @param  array<int, array<string, mixed>>  $ingredients
     */
    private function assertIngredientsBelongToShop(array $ingredients, int $atelierId, ?int $parentGoodId): void
    {
        if (count($ingredients) === 0) {
            return;
        }

        $hasNestedCol = Schema::hasColumn('produced_good_ingredients', 'ingredient_produced_good_id');
        $rawIds = [];
        $nestedIds = [];

        foreach ($ingredients as $row) {
            $rawId = (int) ($row['raw_material_id'] ?? 0);
            $nestedId = (int) ($row['ingredient_produced_good_id'] ?? 0);

            if ($rawId > 0 && $nestedId > 0) {
                abort(response()->json([
                    'message' => 'هر خط فرمول باید ماده اولیه یا کالای تولیدی باشد، نه هر دو.',
                ], 422));
            }
            if ($rawId <= 0 && $nestedId <= 0) {
                abort(response()->json([
                    'message' => 'هر خط فرمول باید ماده اولیه یا کالای تولیدی داشته باشد.',
                ], 422));
            }
            if ($nestedId > 0 && ! $hasNestedCol) {
                abort(response()->json([
                    'message' => 'ستون کالای تولیدی در فرمول وجود ندارد. migration یا SQL را اجرا کنید.',
                ], 503));
            }
            if ($nestedId > 0 && $parentGoodId !== null && $nestedId === (int) $parentGoodId) {
                abort(response()->json([
                    'message' => 'کالا نمی‌تواند خودش را در فرمول داشته باشد.',
                ], 422));
            }

            if ($rawId > 0) {
                $rawIds[] = $rawId;
            } else {
                $nestedIds[] = $nestedId;
            }
        }

        if (count($rawIds) !== count(array_unique($rawIds))) {
            abort(response()->json([
                'message' => 'هر ماده اولیه فقط یک‌بار در فرمول کالا می‌تواند بیاید.',
            ], 422));
        }
        if (count($nestedIds) !== count(array_unique($nestedIds))) {
            abort(response()->json([
                'message' => 'هر کالای تولیدی فقط یک‌بار در فرمول کالا می‌تواند بیاید.',
            ], 422));
        }

        if (count($rawIds) > 0) {
            $found = RawMaterial::where('atelier_id', $atelierId)
                ->whereIn('id', $rawIds)
                ->count();
            if ($found !== count($rawIds)) {
                abort(response()->json([
                    'message' => 'یک یا چند ماده اولیه متعلق به این فروشگاه نیست.',
                ], 422));
            }
        }

        if (count($nestedIds) > 0) {
            $found = ProducedGood::where('atelier_id', $atelierId)
                ->whereIn('id', $nestedIds)
                ->count();
            if ($found !== count($nestedIds)) {
                abort(response()->json([
                    'message' => 'یک یا چند کالای تولیدی متعلق به این فروشگاه نیست.',
                ], 422));
            }

            if ($parentGoodId !== null) {
                $this->assertNoIngredientCycle($parentGoodId, $nestedIds);
            }
        }
    }

    /**
     * جلوگیری از حلقه: ساندویچ ← برگر ← ساندویچ.
     *
     * @param  array<int, int>  $directNestedIds
     */
    private function assertNoIngredientCycle(int $parentGoodId, array $directNestedIds): void
    {
        if (! Schema::hasColumn('produced_good_ingredients', 'ingredient_produced_good_id')) {
            return;
        }

        $queue = array_values(array_unique(array_map('intval', $directNestedIds)));
        $seen = [];
        $cycleMessage = 'فرمول حلقه‌ای است؛ یک کالای تولیدی نمی‌تواند از طریق فرمول‌های دیگر به خودش برسد.';

        while ($queue !== []) {
            $current = array_shift($queue);
            if ($current <= 0 || isset($seen[$current])) {
                continue;
            }
            if ($current === (int) $parentGoodId) {
                abort(response()->json(['message' => $cycleMessage], 422));
            }
            $seen[$current] = true;

            $childIds = \App\Models\ProducedGoodIngredient::query()
                ->where('produced_good_id', $current)
                ->whereNotNull('ingredient_produced_good_id')
                ->pluck('ingredient_produced_good_id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->all();

            foreach ($childIds as $childId) {
                if ($childId === (int) $parentGoodId) {
                    abort(response()->json(['message' => $cycleMessage], 422));
                }
                if (! isset($seen[$childId])) {
                    $queue[] = $childId;
                }
            }
        }
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

    private function loadProducedGoodRelations(ProducedGood $good): ProducedGood
    {
        $with = $this->ingredientWith();
        if (Schema::hasTable('category_produced_good')) {
            $with[] = 'categories';
        }

        return $good->load($with);
    }

    private function syncIngredients(ProducedGood $good, array $ingredients): void
    {
        $good->ingredients()->delete();

        $hasRemovable = Schema::hasColumn('produced_good_ingredients', 'is_removable');
        $hasAddable = Schema::hasColumn('produced_good_ingredients', 'is_addable');
        $hasNested = Schema::hasColumn('produced_good_ingredients', 'ingredient_produced_good_id');

        foreach ($ingredients as $row) {
            $rawId = (int) ($row['raw_material_id'] ?? 0);
            $nestedId = (int) ($row['ingredient_produced_good_id'] ?? 0);
            $isNested = $nestedId > 0;

            $payload = [
                'raw_material_id' => $isNested ? null : $rawId,
                'grams_per_kg' => $row['grams_per_kg'],
            ];
            if ($hasNested) {
                $payload['ingredient_produced_good_id'] = $isNested ? $nestedId : null;
            }
            if ($hasRemovable) {
                // حذف/اضافه فقط برای مواد اولیه منوی سه‌بعدی معنا دارد
                $payload['is_removable'] = ! $isNested && ! empty($row['is_removable']);
            }
            if ($hasAddable) {
                $payload['is_addable'] = ! $isNested && ! empty($row['is_addable']);
            }
            $good->ingredients()->create($payload);
        }
    }

    /**
     * ترتیب لایه‌های عکس (بالا به پایین). هر ماده می‌تواند چند بار بیاید؛ فقط مواد فرمول مجازند.
     * هر آیتم: شناسه ماده یا { raw_material_id, scale_x, scale_y, gap }.
     *
     * @param  array<int, mixed>|null  $layerOrder
     */
    private function syncLayerOrder(ProducedGood $good, ?array $layerOrder): void
    {
        if (! Schema::hasColumn('produced_goods', 'layer_order')) {
            return;
        }

        // لایه‌های منوی سه‌بعدی فقط مواد اولیه هستند؛ کالای تولیدی تو در تو لایه ندارد.
        $allowed = $good->ingredients()
            ->whereNotNull('raw_material_id')
            ->pluck('raw_material_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();

        $clampScale = static function ($value): float {
            $n = is_numeric($value) ? (float) $value : 1.0;
            if (! is_finite($n)) {
                $n = 1.0;
            }

            return max(0.2, min(3.0, round($n, 2)));
        };
        $clampGap = static function ($value): float {
            $n = is_numeric($value) ? (float) $value : 0.0;
            if (! is_finite($n)) {
                $n = 0.0;
            }

            return max(-40.0, min(80.0, round($n, 1)));
        };

        $clean = [];
        foreach ((array) $layerOrder as $row) {
            $id = 0;
            $scaleX = 1.0;
            $scaleY = 1.0;
            $gap = 0.0;
            if (is_array($row)) {
                $id = (int) ($row['raw_material_id'] ?? $row['id'] ?? 0);
                $legacy = array_key_exists('scale', $row) ? (float) $row['scale'] : null;
                $scaleX = array_key_exists('scale_x', $row)
                    ? (float) $row['scale_x']
                    : ($legacy ?? 1.0);
                $scaleY = array_key_exists('scale_y', $row)
                    ? (float) $row['scale_y']
                    : ($legacy ?? 1.0);
                $gap = array_key_exists('gap', $row) ? (float) $row['gap'] : 0.0;
            } else {
                $id = (int) $row;
            }
            if ($id <= 0 || ! in_array($id, $allowed, true)) {
                continue;
            }
            $scaleX = $clampScale($scaleX);
            $scaleY = $clampScale($scaleY);
            $gap = $clampGap($gap);
            $clean[] = [
                'raw_material_id' => $id,
                'scale_x' => $scaleX,
                'scale_y' => $scaleY,
                // سازگاری با کلاینت قدیمی
                'scale' => $scaleX === $scaleY ? $scaleX : round(($scaleX + $scaleY) / 2, 2),
                'gap' => $gap,
            ];
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
