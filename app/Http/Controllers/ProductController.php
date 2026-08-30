<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProducedGood;
use App\Services\ProducedGoodCostService;
use App\Tools\ImageTools;
use App\Tools\PriceTools;
use App\Tools\ProductQuantityTools;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    /**
     * نمایش لیست محصولات
     */
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $query = Product::query()->where('atelier_id', $atelierId);
        
        // جستجو بر اساس searchFilterModel یا search
        $searchDataModel = null;
        if ($request->has('searchFilterModel')) {
            $searchDataModel = json_decode($request->input('searchFilterModel'));
        } elseif ($request->has('search')) {
            // اگر search استفاده شده، آن را به عنوان رشته ساده در نظر می‌گیریم
            $searchDataModel = $request->input('search');
        }
        
        if ($searchDataModel) {
            $query->where(function($q) use ($searchDataModel) {
                if (is_object($searchDataModel)) {
                    // جستجو بر اساس نام محصول
                    if (isset($searchDataModel->name)) {
                        $q->where('name', 'like', '%' . $searchDataModel->name . '%');
                    }
                    // جستجو بر اساس بارکد
                    if (isset($searchDataModel->barcode)) {
                        $q->orWhere('barcode', 'like', '%' . $searchDataModel->barcode . '%');
                    }
                } else if (is_string($searchDataModel)) {
                    // اگر یک رشته ساده بود، در نام و بارکد جستجو می‌کند
                    $q->where('name', 'like', '%' . $searchDataModel . '%')
                      ->orWhere('barcode', 'like', '%' . $searchDataModel . '%');
                }
            });
        }

        $categoryId = $request->filled('category_id') ? (int) $request->input('category_id') : null;
        if ($categoryId) {
            $query->whereHas('categories', function ($q) use ($categoryId) {
                $q->where('categories.id', $categoryId);
            });
        }

        $sort = $this->resolveProductListSort($request);
        $this->applyProductListSort($query, $sort);

        $perPage = max(1, (int) $request->input('per_page', 10));

        $products = $query->with(['images', 'categories', 'manufacturer'])->get()->map(function ($product) {
            $product->item_type = 'product';
            $product->product_id = $product->id;
            $product->produced_good_id = null;

            return $this->appendProductPricingMeta($product);
        });

        $producedGoods = $this->producedGoodsForProductAll($request, $atelierId, $searchDataModel, $categoryId);
        $merged = $producedGoods->concat($products)->values();
        $paginator = $this->paginateMergedCatalog($merged, $request, $perPage);
        $paginator->appends($request->only(['sort', 'order_by', 'search', 'searchFilterModel', 'per_page', 'category_id']));

        return response($paginator)->header('X-Applied-Sort', $sort !== '' ? $sort : 'id_desc');
    }

    /**
     * افزودن محصول جدید
     */
    public function store(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            abort(response()->json([
                'message' => 'ثبت محصول فقط با حساب پرسنل متصل به فروشگاه (کاربر با atelier_id) امکان‌پذیر است.',
            ], 422));
        }

        if ($request->has('barcode')) {
            $request->merge(['barcode' => trim((string) $request->input('barcode'))]);
        }

        $fields = $request->validate(
            $this->productValidationRules($atelierId),
            $this->productValidationMessages()
        );

        $prepared = $this->prepareProductFieldsForCreate($fields);
        if (is_string($prepared)) {
            return response(['message' => $prepared], 422);
        }

        $product = $this->createProductFromPreparedFields($prepared, $atelierId, $request->all());
        $product->load(['images', 'categories']);

        return response($this->appendProductPricingMeta($product), 201);
    }

    /**
     * ثبت/به‌روزرسانی گروهی: اگر بارکد در فروشگاه باشد آپدیت، وگرنه ثبت جدید.
     */
    public function bulkStore(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            abort(response()->json([
                'message' => 'ثبت محصول فقط با حساب پرسنل متصل به فروشگاه (کاربر با atelier_id) امکان‌پذیر است.',
            ], 422));
        }

        $productsData = $request->input('products', []);
        if (! is_array($productsData)) {
            return response(['message' => 'فرمت products نامعتبر است.'], 422);
        }

        $request->validate([
            'products' => 'required|array|min:1|max:200',
            'products.*.name' => 'required|string|max:255',
            'products.*.purchase_price' => 'required|numeric|min:0',
            'products.*.sale_price' => 'required|numeric|min:0',
            'products.*.quantity' => 'required|numeric|min:0',
            'products.*.unit_type' => 'nullable|string|in:'.Product::UNIT_PIECE.','.Product::UNIT_KG,
            'products.*.barcode' => 'nullable|string|min:1|max:255',
            'products.*.original_sale_price' => 'nullable|numeric|min:0',
            'products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'products.*.manufacturer_id' => 'nullable|exists:manufacturers,id',
            'products.*.images' => 'nullable|array',
            'products.*.images.*' => 'nullable|string',
            'products.*.category_ids' => 'nullable|array',
            'products.*.category_ids.*' => 'exists:categories,id',
            'products.*.sizes' => 'nullable|array',
            'products.*.sizes.*' => 'string',
            'products.*.colors' => 'nullable|array',
            'products.*.colors.*' => 'string',
        ], $this->productValidationMessages());

        $barcodesInRequest = [];
        foreach ($productsData as $productData) {
            $barcode = trim((string) ($productData['barcode'] ?? ''));
            if ($barcode === '') {
                continue;
            }

            if (isset($barcodesInRequest[$barcode])) {
                return response(['message' => "بارکد «{$barcode}» در لیست تکراری است."], 422);
            }
            $barcodesInRequest[$barcode] = true;
        }

        $existingByBarcode = collect();
        if (! empty($barcodesInRequest)) {
            $existingByBarcode = Product::where('atelier_id', $atelierId)
                ->whereIn('barcode', array_keys($barcodesInRequest))
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('barcode');
        }

        $createdProducts = [];
        $updatedProducts = [];

        try {
            DB::transaction(function () use ($productsData, $atelierId, $existingByBarcode, &$createdProducts, &$updatedProducts) {
                foreach ($productsData as $productData) {
                    if (isset($productData['barcode'])) {
                        $productData['barcode'] = trim((string) $productData['barcode']);
                        if ($productData['barcode'] === '') {
                            unset($productData['barcode']);
                        }
                    }

                    $barcode = $productData['barcode'] ?? null;
                    $existing = $barcode !== null ? $existingByBarcode->get($barcode) : null;

                    if ($existing) {
                        $prepared = $this->prepareProductFieldsForUpdate($productData, $existing);
                        if (is_string($prepared)) {
                            throw new \InvalidArgumentException($prepared);
                        }

                        $existing->update($prepared);
                        $this->syncProductRelations($existing, $productData);
                        $existing->load(['images', 'categories']);
                        $updatedProducts[] = $this->appendProductPricingMeta($existing);
                    } else {
                        $prepared = $this->prepareProductFieldsForCreate($productData);
                        if (is_string($prepared)) {
                            throw new \InvalidArgumentException($prepared);
                        }

                        $product = $this->createProductFromPreparedFields($prepared, $atelierId, $productData);
                        $product->load(['images', 'categories']);
                        $createdProducts[] = $this->appendProductPricingMeta($product);
                    }
                }
            });
        } catch (\InvalidArgumentException $e) {
            return response(['message' => $e->getMessage()], 422);
        }

        return response([
            'message' => 'محصولات با موفقیت پردازش شدند.',
            'created_count' => count($createdProducts),
            'updated_count' => count($updatedProducts),
            'created' => $createdProducts,
            'updated' => $updatedProducts,
            'products' => array_merge($createdProducts, $updatedProducts),
        ], 200);
    }

    /**
     * تولید بارکد موقت برای ایجاد محصول
     */
    private function generateTemporaryBarcode(int $atelierId)
    {
        do {
            // استفاده از timestamp و عدد تصادفی برای تولید بارکد موقت
            $barcode = 'TMP' . time() . rand(10000, 99999);
        } while (Product::where('barcode', $barcode)->where('atelier_id', $atelierId)->exists());

        return $barcode;
    }

    private function productValidationRules(int $atelierId, ?int $ignoreProductId = null): array
    {
        $barcodeRules = $ignoreProductId === null
            ? ['nullable', 'string', 'min:1', 'max:255', $this->uniqueBarcodeRule($atelierId)]
            : ['required', 'string', 'min:1', 'max:255', $this->uniqueBarcodeRule($atelierId, $ignoreProductId)];

        return [
            'name' => 'required|string|max:255',
            'purchase_price' => 'required|numeric|min:0',
            'sale_price' => 'required|numeric|min:0',
            'quantity' => 'required|numeric|min:0',
            'unit_type' => 'nullable|string|in:'.Product::UNIT_PIECE.','.Product::UNIT_KG,
            'barcode' => $barcodeRules,
            'original_sale_price' => 'nullable|numeric|min:0',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'manufacturer_id' => 'nullable|exists:manufacturers,id',
            'images' => 'nullable|array',
            'images.*' => 'nullable|string',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'exists:categories,id',
            'sizes' => 'nullable|array',
            'sizes.*' => 'string',
            'colors' => 'nullable|array',
            'colors.*' => 'string',
        ];
    }

    private function productValidationMessages(): array
    {
        return [
            'barcode.unique' => 'این بارکد قبلاً برای کالای دیگری در همین فروشگاه ثبت شده است.',
            'barcode.required' => 'وارد کردن بارکد الزامی است.',
            'name.required' => 'نام کالا الزامی است.',
            'purchase_price.required' => 'قیمت خرید الزامی است.',
            'sale_price.required' => 'قیمت فروش الزامی است.',
            'quantity.required' => 'موجودی الزامی است.',
        ];
    }

    /**
     * قانون یکتایی بارکد در همان فروشگاه (محصول‌های حذف‌شده از لیست شمرده نمی‌شوند).
     */
    private function uniqueBarcodeRule(int $atelierId, ?int $ignoreProductId = null): \Illuminate\Validation\Rules\Unique
    {
        $rule = Rule::unique('products', 'barcode')->where(function ($query) use ($atelierId) {
            return $query->where('atelier_id', $atelierId)->whereNull('deleted_at');
        });

        if ($ignoreProductId !== null) {
            $rule->ignore($ignoreProductId);
        }

        return $rule;
    }

    /**
     * دریافت تمام محصولات بدون pagination
     */
    public function getAll(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $query = Product::query()->where('atelier_id', $atelierId);
        
        // جستجو بر اساس searchFilterModel یا search
        $searchDataModel = null;
        if ($request->has('searchFilterModel')) {
            $searchDataModel = json_decode($request->input('searchFilterModel'));
        } elseif ($request->has('search')) {
            // اگر search استفاده شده، آن را به عنوان رشته ساده در نظر می‌گیریم
            $searchDataModel = $request->input('search');
        }
        
        if ($searchDataModel) {
            $query->where(function($q) use ($searchDataModel) {
                if (is_object($searchDataModel)) {
                    // جستجو بر اساس نام محصول
                    if (isset($searchDataModel->name)) {
                        $q->where('name', 'like', '%' . $searchDataModel->name . '%');
                    }
                    // جستجو بر اساس بارکد
                    if (isset($searchDataModel->barcode)) {
                        $q->orWhere('barcode', 'like', '%' . $searchDataModel->barcode . '%');
                    }
                } else if (is_string($searchDataModel)) {
                    // اگر یک رشته ساده بود، در نام و بارکد جستجو می‌کند
                    $q->where('name', 'like', '%' . $searchDataModel . '%')
                      ->orWhere('barcode', 'like', '%' . $searchDataModel . '%');
                }
            });
        }
        
        $categoryId = $request->filled('category_id') ? (int) $request->input('category_id') : null;
        if ($categoryId) {
            $query->whereHas('categories', function ($q) use ($categoryId) {
                $q->where('categories.id', $categoryId);
            });
        }

        $products = $query->with(['images', 'categories', 'manufacturer'])->orderBy('id', 'desc')->get();

        $products->transform(function ($product) {
            $product->item_type = 'product';
            $product->product_id = $product->id;
            $product->produced_good_id = null;

            return $this->appendProductPricingMeta($product);
        });

        $producedGoods = $this->producedGoodsForProductAll($request, $atelierId, $searchDataModel, $categoryId);

        return response($producedGoods->concat($products)->values());
    }

    /**
     * محصول متعلق به فروشگاه جاری (مسیر api/{shop}/product/{id}).
     */
    protected function resolveShopProduct(Request $request, $productId): Product
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $product = Product::query()->where('id', $productId)->first();
        if (! $product) {
            abort(response()->json(['message' => 'محصول یافت نشد'], 404));
        }

        if ($product->atelier_id !== null && (int) $product->atelier_id !== $atelierId) {
            abort(response()->json(['message' => 'محصول یافت نشد'], 404));
        }

        return $product;
    }

    /**
     * نمایش جزئیات یک محصول
     */
    public function show(Request $request, $product, string $shop = '')
    {
        if ($request->input('item_type') === 'produced_good' || $request->boolean('produced_good')) {
            $good = $this->resolveShopProducedGood($request, $product);
            if ($good) {
                return response($this->formatProducedGoodCatalogItem($good));
            }
        }

        $model = Product::query()->where('id', $product)->first();
        if ($model) {
            $atelierId = $this->shopAtelierIdOrAbort($request);
            if ($model->atelier_id !== null && (int) $model->atelier_id !== $atelierId) {
                abort(response()->json(['message' => 'محصول یافت نشد'], 404));
            }
            $model->load(['images', 'categories', 'manufacturer']);
            $model->item_type = 'product';
            $model->product_id = $model->id;
            $model->produced_good_id = null;

            return response($this->appendProductPricingMeta($model));
        }

        $good = $this->resolveShopProducedGood($request, $product);
        if ($good) {
            return response($this->formatProducedGoodCatalogItem($good));
        }

        abort(response()->json(['message' => 'محصول یافت نشد'], 404));
    }

    /**
     * دریافت محصولات پرفروش
     * بر اساس تعداد فروش (مجموع quantity در purchased_products)
     * 
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function bestSelling(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $limit = max(1, min((int) $request->input('limit', 10), 100));

        $salesQuery = $this->bestSellingSalesQuery($atelierId);

        $productIds = (clone $salesQuery)
            ->select('purchased_products.product_id', DB::raw('SUM(purchased_products.quantity) as total_sold'))
            ->groupBy('purchased_products.product_id')
            ->orderByDesc('total_sold')
            ->limit($limit)
            ->pluck('product_id')
            ->toArray();

        if (empty($productIds)) {
            $bestSellingProducts = Product::with(['images', 'categories', 'manufacturer'])
                ->where('atelier_id', $atelierId)
                ->orderByDesc('id')
                ->limit($limit)
                ->get();

            $bestSellingProducts->each(function ($product) {
                $product->total_sold = 0;
            });
        } else {
            $totalSoldMap = (clone $salesQuery)
                ->select('purchased_products.product_id', DB::raw('SUM(purchased_products.quantity) as total_sold'))
                ->whereIn('purchased_products.product_id', $productIds)
                ->groupBy('purchased_products.product_id')
                ->pluck('total_sold', 'product_id')
                ->toArray();

            $products = Product::whereIn('id', $productIds)
                ->where('atelier_id', $atelierId)
                ->with(['images', 'categories', 'manufacturer'])
                ->get();

            $bestSellingProducts = collect($productIds)->map(function ($productId) use ($products, $totalSoldMap) {
                $product = $products->firstWhere('id', $productId);
                if (! $product) {
                    return null;
                }
                $product->total_sold = (int) ($totalSoldMap[$productId] ?? 0);

                return $product;
            })->filter()->values();
        }

        $bestSellingProducts->transform(function ($product) {
            return $this->appendProductPricingMeta($product);
        });

        return response($bestSellingProducts, 200)
            ->header('X-Atelier-Id', (string) $atelierId);
    }

    /**
     * پایهٔ کوئری فروش برای پرفروش‌ها — فقط محصولات و خریدهای همان فروشگاه.
     */
    private function bestSellingSalesQuery(int $atelierId)
    {
        return DB::table('purchased_products')
            ->join('purchases', 'purchased_products.purchase_id', '=', 'purchases.id')
            ->join('products', 'purchased_products.product_id', '=', 'products.id')
            ->where('products.atelier_id', $atelierId)
            ->where(function ($q) use ($atelierId) {
                $q->where('purchases.atelier_id', $atelierId)
                    ->orWhereNull('purchases.atelier_id');
            });
    }

    /**
     * ویرایش اطلاعات محصول (یا کالای تولیدی اگر همان شناسه محصول نباشد)
     */
    public function update(Request $request, $product)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            abort(response()->json([
                'message' => 'ویرایش محصول فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422));
        }

        $preferProduced = $request->input('item_type') === 'produced_good' || $request->boolean('produced_good');

        if (! $preferProduced) {
            $model = Product::query()->where('id', $product)->where('atelier_id', $atelierId)->first();
            if ($model) {
                return $this->updateCatalogProduct($request, $model, $atelierId);
            }
        }

        $good = $this->resolveShopProducedGood($request, $product);
        if ($good) {
            return $this->updateProducedGoodAsCatalogItem($request, $good, $atelierId);
        }

        return response(['message' => 'محصول یافت نشد'], 404);
    }

    private function updateCatalogProduct(Request $request, Product $product, int $atelierId)
    {
        if ($request->has('barcode')) {
            $request->merge(['barcode' => trim((string) $request->input('barcode'))]);
        }

        $fields = $request->validate(
            $this->productValidationRules($atelierId, $product->id),
            $this->productValidationMessages()
        );

        $prepared = $this->prepareProductFieldsForUpdate($fields, $product);
        if (is_string($prepared)) {
            return response(['message' => $prepared], 422);
        }

        $product->update($prepared);
        $this->syncProductRelations($product, $request->all());

        $product->load(['images', 'categories']);
        $product->item_type = 'product';
        $product->product_id = $product->id;
        $product->produced_good_id = null;

        return response($this->appendProductPricingMeta($product));
    }

    private function updateProducedGoodAsCatalogItem(Request $request, ProducedGood $good, int $atelierId)
    {
        $fields = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'sale_price' => 'nullable|numeric|min:0',
            'original_sale_price' => 'nullable|numeric|min:0',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'markup_percent' => 'nullable|numeric|min:0|max:9999',
            'round_sale_price' => 'sometimes|boolean',
            'note' => 'nullable|string',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|exists:categories,id',
            'purchase_price' => 'nullable',
            'quantity' => 'nullable',
            'barcode' => 'nullable',
            'unit_type' => 'nullable',
            'images' => 'nullable',
            'manufacturer_id' => 'nullable',
            'sizes' => 'nullable',
            'colors' => 'nullable',
            'item_type' => 'nullable|string',
            'produced_good_id' => 'nullable',
            'product_id' => 'nullable',
        ]);

        $payload = [];
        if (array_key_exists('name', $fields)) {
            $payload['name'] = $fields['name'];
        }
        if (array_key_exists('note', $fields)) {
            $payload['note'] = $fields['note'];
        }
        if (array_key_exists('round_sale_price', $fields)) {
            $payload['round_sale_price'] = filter_var($fields['round_sale_price'], FILTER_VALIDATE_BOOLEAN);
        }

        $hasOriginalColumn = Schema::hasColumn('produced_goods', 'original_sale_price');
        $currentSale = (float) $good->sale_price;
        $currentOriginal = $hasOriginalColumn
            ? (float) ($good->original_sale_price ?? $currentSale)
            : $currentSale;

        if (array_key_exists('discount_percent', $fields) && $fields['discount_percent'] !== null) {
            $basePrice = array_key_exists('sale_price', $fields) && $fields['sale_price'] !== null
                ? (float) $fields['sale_price']
                : $currentOriginal;
            $discountAmount = ($basePrice * (float) $fields['discount_percent']) / 100;
            $payload['sale_price'] = PriceTools::roundSalePrice((float) max(0, $basePrice - $discountAmount));
            if ($hasOriginalColumn) {
                $payload['original_sale_price'] = PriceTools::roundSalePrice($basePrice);
            }
            $payload['markup_percent'] = null;
        } elseif (array_key_exists('sale_price', $fields) && $fields['sale_price'] !== null) {
            $payload['sale_price'] = PriceTools::roundSalePrice((float) $fields['sale_price']);
            $payload['markup_percent'] = null;
            if ($hasOriginalColumn && array_key_exists('original_sale_price', $fields) && $fields['original_sale_price'] !== null) {
                $payload['original_sale_price'] = PriceTools::roundSalePrice((float) $fields['original_sale_price']);
            }
        } elseif ($hasOriginalColumn && array_key_exists('original_sale_price', $fields) && $fields['original_sale_price'] !== null) {
            $payload['original_sale_price'] = PriceTools::roundSalePrice((float) $fields['original_sale_price']);
        }

        if (array_key_exists('markup_percent', $fields) && $fields['markup_percent'] !== null && $fields['markup_percent'] !== '') {
            $payload['markup_percent'] = round((float) $fields['markup_percent'], 2);
        }

        DB::transaction(function () use ($good, $payload, $fields, $atelierId) {
            if ($payload !== []) {
                $good->update($payload);
            }
            if (array_key_exists('category_ids', $fields)) {
                $this->syncProducedGoodCatalogCategories($good, $fields['category_ids'] ?? [], $atelierId);
            }
        });

        $good = $good->fresh();
        app(ProducedGoodCostService::class)->attachCost($good, 1.0, true);

        return response($this->formatProducedGoodCatalogItem($good));
    }

    private function syncProducedGoodCatalogCategories(ProducedGood $good, array $categoryIds, int $atelierId): void
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

    /**
     * ویرایش گروهی محصولات در یک درخواست
     */
    public function bulkUpdate(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            abort(response()->json([
                'message' => 'ویرایش محصول فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422));
        }

        $productsData = $request->input('products', []);
        if (! is_array($productsData)) {
            return response(['message' => 'فرمت products نامعتبر است.'], 422);
        }

        $request->validate([
            'products' => 'required|array|min:1|max:200',
            'products.*.id' => 'required|integer|distinct',
            'products.*.name' => 'required|string|max:255',
            'products.*.purchase_price' => 'required|numeric|min:0',
            'products.*.sale_price' => 'required|numeric|min:0',
            'products.*.quantity' => 'required|numeric|min:0',
            'products.*.unit_type' => 'nullable|string|in:'.Product::UNIT_PIECE.','.Product::UNIT_KG,
            'products.*.barcode' => 'required|string|min:1|max:255',
            'products.*.original_sale_price' => 'nullable|numeric|min:0',
            'products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'products.*.manufacturer_id' => 'nullable|exists:manufacturers,id',
            'products.*.category_ids' => 'nullable|array',
            'products.*.category_ids.*' => 'exists:categories,id',
            'products.*.sizes' => 'nullable|array',
            'products.*.sizes.*' => 'string',
            'products.*.colors' => 'nullable|array',
            'products.*.colors.*' => 'string',
        ], $this->productValidationMessages());

        $productIds = collect($productsData)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $products = Product::whereIn('id', $productIds)
            ->where('atelier_id', $atelierId)
            ->get()
            ->keyBy('id');

        if ($products->count() !== count(array_unique($productIds))) {
            return response(['message' => 'برخی محصولات یافت نشد یا متعلق به فروشگاه شما نیست.'], 422);
        }

        $barcodesInRequest = [];
        foreach ($productsData as $index => $productData) {
            $barcode = trim((string) ($productData['barcode'] ?? ''));
            if ($barcode === '') {
                return response(['message' => "ردیف {$index}: بارکد الزامی است."], 422);
            }

            if (isset($barcodesInRequest[$barcode])) {
                return response(['message' => "بارکد «{$barcode}» در لیست تکراری است."], 422);
            }
            $barcodesInRequest[$barcode] = (int) $productData['id'];
        }

        foreach ($barcodesInRequest as $barcode => $productId) {
            $exists = Product::where('barcode', $barcode)
                ->where('atelier_id', $atelierId)
                ->where('id', '!=', $productId)
                ->whereNull('deleted_at')
                ->exists();

            if ($exists) {
                return response(['message' => "بارکد «{$barcode}» قبلاً برای کالای دیگری ثبت شده است."], 422);
            }
        }

        $updatedProducts = [];

        try {
            DB::transaction(function () use ($productsData, $products, &$updatedProducts) {
                foreach ($productsData as $productData) {
                    $product = $products->get((int) $productData['id']);
                    $productData['barcode'] = trim((string) $productData['barcode']);

                    $prepared = $this->prepareProductFieldsForUpdate($productData, $product);
                    if (is_string($prepared)) {
                        throw new \InvalidArgumentException($prepared);
                    }

                    $product->update($prepared);
                    $this->syncProductRelations($product, $productData);

                    $product->load(['images', 'categories']);
                    $updatedProducts[] = $this->appendProductPricingMeta($product);
                }
            });
        } catch (\InvalidArgumentException $e) {
            return response(['message' => $e->getMessage()], 422);
        }

        return response([
            'message' => 'محصولات با موفقیت به‌روزرسانی شدند.',
            'updated_count' => count($updatedProducts),
            'products' => $updatedProducts,
        ]);
    }

    /**
     * آماده‌سازی فیلدهای به‌روزرسانی محصول (قیمت، موجودی، واحد).
     *
     * @return array<string, mixed>|string
     */
    private function prepareProductFieldsForUpdate(array $fields, Product $product)
    {
        $fields['unit_type'] = $fields['unit_type'] ?? ($product->unit_type ?? Product::UNIT_PIECE);
        if ($error = ProductQuantityTools::validateProductStockQuantity($fields['quantity'], $fields['unit_type'])) {
            return $error;
        }
        $fields['quantity'] = ProductQuantityTools::normalize($fields['quantity'], $fields['unit_type']);

        if (isset($fields['discount_percent'])) {
            if ($fields['discount_percent'] >= 0) {
                $basePrice = $fields['sale_price'] ?? $product->original_sale_price;
                $discountAmount = ($basePrice * $fields['discount_percent']) / 100;
                $priceAfterDiscount = max(0, $basePrice - $discountAmount);
                $fields['sale_price'] = PriceTools::roundSalePrice((float) $priceAfterDiscount);
                $fields['original_sale_price'] = PriceTools::roundSalePrice((float) $basePrice);
            }
            unset($fields['discount_percent']);
        } else {
            $fields['sale_price'] = PriceTools::roundSalePrice((float) $fields['sale_price']);
            if (! isset($fields['original_sale_price'])) {
                if ($product->original_sale_price !== null) {
                    $fields['original_sale_price'] = PriceTools::roundSalePrice((float) $product->original_sale_price);
                } else {
                    $fields['original_sale_price'] = $fields['sale_price'];
                }
            } else {
                $fields['original_sale_price'] = PriceTools::roundSalePrice((float) $fields['original_sale_price']);
            }
        }

        unset($fields['id'], $fields['images'], $fields['category_ids']);

        return $fields;
    }

    /**
     * آماده‌سازی فیلدهای ثبت محصول (قیمت، موجودی، واحد).
     *
     * @return array<string, mixed>|string
     */
    private function prepareProductFieldsForCreate(array $fields)
    {
        $fields['unit_type'] = $fields['unit_type'] ?? Product::UNIT_PIECE;
        if ($error = ProductQuantityTools::validateProductStockQuantity($fields['quantity'], $fields['unit_type'])) {
            return $error;
        }
        $fields['quantity'] = ProductQuantityTools::normalize($fields['quantity'], $fields['unit_type']);

        if (isset($fields['discount_percent']) && $fields['discount_percent'] > 0) {
            $basePrice = $fields['original_sale_price'] ?? $fields['sale_price'];
            $discountAmount = ($basePrice * $fields['discount_percent']) / 100;
            $priceAfterDiscount = max(0, $basePrice - $discountAmount);
            $fields['sale_price'] = PriceTools::roundSalePrice((float) $priceAfterDiscount);
            $fields['original_sale_price'] = PriceTools::roundSalePrice((float) $basePrice);
        } else {
            $fields['sale_price'] = PriceTools::roundSalePrice((float) $fields['sale_price']);
            if (! isset($fields['original_sale_price'])) {
                $fields['original_sale_price'] = $fields['sale_price'];
            } else {
                $fields['original_sale_price'] = PriceTools::roundSalePrice((float) $fields['original_sale_price']);
            }
        }

        unset($fields['discount_percent'], $fields['images'], $fields['category_ids']);

        if (isset($fields['barcode'])) {
            $fields['barcode'] = trim((string) $fields['barcode']);
            if ($fields['barcode'] === '') {
                unset($fields['barcode']);
            }
        }

        return $fields;
    }

    /**
     * ایجاد محصول از فیلدهای آماده‌شده.
     */
    private function createProductFromPreparedFields(array $fields, int $atelierId, array $relationsData = []): Product
    {
        $sellerBarcode = $fields['barcode'] ?? null;

        if ($sellerBarcode === null) {
            $fields['barcode'] = $this->generateTemporaryBarcode($atelierId);
        }

        $fields['atelier_id'] = $atelierId;
        $product = Product::create($fields);

        if ($sellerBarcode === null) {
            $product->barcode = (string) $product->id;
            $product->save();
        }

        $this->syncProductRelations($product, $relationsData);

        return $product;
    }

    /**
     * همگام‌سازی دسته‌بندی‌ها و عکس‌های محصول در صورت ارسال.
     */
    private function syncProductRelations(Product $product, array $data): void
    {
        if (array_key_exists('images', $data) && is_array($data['images']) && ! empty($data['images'])) {
            $this->saveProductImages($product, $data['images']);
        }

        if (array_key_exists('category_ids', $data)) {
            if (is_array($data['category_ids']) && ! empty($data['category_ids'])) {
                $product->categories()->sync($data['category_ids']);
            } else {
                $product->categories()->detach();
            }
        }
    }

    /**
     * حذف از لیست (soft delete) — رکورد و سابقه خرید در دیتابیس می‌ماند.
     */
    public function destroy(Request $request, $product)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            abort(response()->json([
                'message' => 'حذف محصول فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422));
        }

        $preferProduced = $request->input('item_type') === 'produced_good' || $request->boolean('produced_good');

        if (! $preferProduced) {
            $model = Product::query()->where('id', $product)->where('atelier_id', $atelierId)->first();
            if ($model) {
                $model->delete();

                return response(['message' => 'محصول از لیست حذف شد. سابقه فروش حفظ شده است.']);
            }
        }

        $good = $this->resolveShopProducedGood($request, $product);
        if ($good) {
            if ($good->productions()->exists()) {
                return response()->json([
                    'message' => 'برای این کالا تولید ثبت شده و قابل حذف نیست.',
                ], 422);
            }
            $good->delete();

            return response(['message' => 'کالای تولیدی حذف شد'], 200);
        }

        return response(['message' => 'محصول یافت نشد'], 404);
    }

    /**
     * اعمال تخفیف به چند محصول
     */
    public function applyDiscount(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            abort(response()->json([
                'message' => 'اعمال تخفیف فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422));
        }

        $request->validate([
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'required|integer',
            'produced_good_ids' => 'nullable|array',
            'produced_good_ids.*' => 'required|integer',
            'discount_percent' => 'required|numeric|min:0|max:100',
        ]);

        $productIds = array_values(array_unique(array_map('intval', $request->input('product_ids', []))));
        $producedGoodIds = array_values(array_unique(array_map('intval', $request->input('produced_good_ids', []))));
        $discountPercent = $request->input('discount_percent');

        if ($productIds === [] && $producedGoodIds === []) {
            return response(['error' => 'حداقل یک کالا یا کالای تولیدی را انتخاب کنید'], 422);
        }

        $products = $productIds === []
            ? collect()
            : Product::whereIn('id', $productIds)->where('atelier_id', $atelierId)->get();

        if ($productIds !== [] && $products->count() !== count($productIds)) {
            return response(['error' => 'برخی شناسه‌های کالا متعلق به این فروشگاه نیستند'], 422);
        }

        $producedGoods = collect();
        if ($producedGoodIds !== []) {
            if (! Schema::hasTable('produced_goods')) {
                return response(['error' => 'جدول کالای تولیدی وجود ندارد'], 422);
            }
            $producedGoods = ProducedGood::whereIn('id', $producedGoodIds)->where('atelier_id', $atelierId)->get();
            if ($producedGoods->count() !== count($producedGoodIds)) {
                return response(['error' => 'برخی شناسه‌های کالای تولیدی متعلق به این فروشگاه نیستند'], 422);
            }
        }

        $updatedProducts = [];
        foreach ($products as $product) {
            $baseSalePrice = $product->original_sale_price ?? $product->sale_price;

            if ($product->original_sale_price === null) {
                $product->original_sale_price = $product->sale_price;
            }

            $discountAmount = ($baseSalePrice * $discountPercent) / 100;
            $priceAfterDiscount = max(0, $baseSalePrice - $discountAmount);
            $newSalePrice = PriceTools::roundSalePrice((float) $priceAfterDiscount);

            $product->sale_price = $newSalePrice;
            $product->save();

            $updatedProducts[] = [
                'id' => $product->id,
                'item_type' => 'product',
                'name' => $product->name,
                'original_sale_price' => $baseSalePrice,
                'sale_price' => $newSalePrice,
                'discount_percent' => $discountPercent,
                'discount_amount' => $discountAmount,
            ];
        }

        foreach ($producedGoods as $good) {
            $hasOriginal = Schema::hasColumn('produced_goods', 'original_sale_price');
            $baseSalePrice = $hasOriginal
                ? ((float) ($good->original_sale_price ?? $good->sale_price))
                : (float) $good->sale_price;

            if ($hasOriginal && $good->original_sale_price === null) {
                $good->original_sale_price = $good->sale_price;
            }

            $discountAmount = ($baseSalePrice * $discountPercent) / 100;
            $priceAfterDiscount = max(0, $baseSalePrice - $discountAmount);
            $newSalePrice = PriceTools::roundSalePrice((float) $priceAfterDiscount);

            $good->sale_price = $newSalePrice;
            $good->markup_percent = null;
            $good->save();

            $updatedProducts[] = [
                'id' => $good->id,
                'item_type' => 'produced_good',
                'produced_good_id' => $good->id,
                'name' => $good->name,
                'original_sale_price' => $baseSalePrice,
                'sale_price' => $newSalePrice,
                'discount_percent' => $discountPercent,
                'discount_amount' => $discountAmount,
            ];
        }

        return response([
            'message' => 'تخفیف با موفقیت اعمال شد',
            'discount_percent' => $discountPercent,
            'updated_count' => count($updatedProducts),
            'products' => $updatedProducts
        ], 200);
    }

    /**
     * ذخیره عکس‌های محصول
     */
    private function saveProductImages(Product $product, array $images)
    {
        // دریافت آخرین order برای ادامه دادن از آن (برای اضافه کردن به عکس‌های قبلی)
        $lastOrder = ProductImage::where('product_id', $product->id)->max('order') ?? 0;
        
        foreach ($images as $imageData) {
            if (empty($imageData)) {
                continue;
            }

            // استخراج base64 از string (اگر به صورت data:image/png;base64,xxx باشد)
            $imageString = $imageData;
            if (strpos($imageData, ',') !== false) {
                $parts = explode(',', $imageData);
                $imageString = $parts[1];
            }

            // decode base64
            $imageContent = base64_decode($imageString);
            if ($imageContent === false) {
                continue;
            }

            // افزایش order برای عکس جدید
            $lastOrder++;

            // ذخیره عکس با نام منحصر به فرد
            $imagePath = ImageTools::saveFile(
                "/products/{$product->id}/image_" . time() . "_" . $lastOrder . ".jpeg",
                $imageContent
            );

            // ذخیره در دیتابیس
            ProductImage::create([
                'product_id' => $product->id,
                'image_path' => $imagePath,
                'order' => $lastOrder,
            ]);
        }
    }

    /**
     * حذف یک عکس خاص از محصول
     */
    public function deleteImage(Request $request, $product, $imageId)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            abort(response()->json([
                'message' => 'حذف تصویر فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422));
        }

        $model = Product::query()->where('id', $product)->where('atelier_id', $atelierId)->first();
        if (! $model) {
            return response(['message' => 'محصول یافت نشد'], 404);
        }
        $product = $model;

        // پیدا کردن عکس
        $productImage = ProductImage::where('id', $imageId)
            ->where('product_id', $product->id)
            ->first();

        if (!$productImage) {
            return response(['error' => 'عکس یافت نشد'], 404);
        }

        // حذف فایل از storage
        $originalPath = $productImage->getOriginal('image_path');
        if ($originalPath && Storage::exists('public/' . $originalPath)) {
            Storage::delete('public/' . $originalPath);
        }

        // حذف از دیتابیس
        $productImage->delete();

        return response(['message' => 'عکس با موفقیت حذف شد'], 200);
    }

    /**
     * حذف عکس‌های محصول
     */
    private function deleteProductImages(Product $product)
    {
        // بارگذاری عکس‌ها برای اطمینان
        $product->load('images');
        
        foreach ($product->images as $image) {
            // حذف فایل از storage
            $originalPath = $image->getOriginal('image_path');
            if ($originalPath && Storage::exists('public/' . $originalPath)) {
                Storage::delete('public/' . $originalPath);
            }
            // حذف از دیتابیس
            $image->delete();
        }
    }

    /**
     * خواندن sort از query، order_by، یا داخل searchFilterModel.
     */
    private function resolveProductListSort(Request $request): string
    {
        $sort = $request->input('sort')
            ?? $request->input('order_by')
            ?? $request->input('order');

        if (($sort === null || $sort === '') && $request->has('searchFilterModel')) {
            $model = json_decode($request->input('searchFilterModel'));
            if (is_object($model) && isset($model->sort)) {
                $sort = $model->sort;
            }
        }

        if (! is_string($sort) || $sort === '') {
            return '';
        }

        $sort = strtolower(trim($sort));
        $sort = str_replace(['-', ' '], '_', $sort);

        $aliases = [
            'highest_profit_percent' => 'profit_percent_desc',
            'lowest_profit_percent' => 'profit_percent_asc',
            'highest_profit' => 'profit_desc',
            'lowest_profit' => 'profit_asc',
            'highest_discount_percent' => 'discount_percent_desc',
            'lowest_discount_percent' => 'discount_percent_asc',
            'highest_discount' => 'discount_desc',
            'lowest_discount' => 'discount_asc',
        ];

        return $aliases[$sort] ?? $sort;
    }

    /**
     * مرتب‌سازی لیست محصولات (قبل از paginate) — با orderByRaw و پیشوند products.
     */
    private function applyProductListSort($query, string $sort): void
    {
        $profitSql = '(products.sale_price - products.purchase_price)';
        $profitPercentSql = '(CASE WHEN products.purchase_price > 0 '
            .'THEN ((products.sale_price - products.purchase_price) / products.purchase_price) * 100 ELSE 0 END)';
        $discountAmountSql = 'GREATEST(0, COALESCE(products.original_sale_price, products.sale_price) - products.sale_price)';
        $discountPercentSql = '(CASE WHEN COALESCE(products.original_sale_price, products.sale_price) > 0 '
            .'THEN (GREATEST(0, COALESCE(products.original_sale_price, products.sale_price) - products.sale_price) '
            .'/ COALESCE(products.original_sale_price, products.sale_price)) * 100 ELSE 0 END)';

        switch ($sort) {
            case 'quantity_desc':
            case 'most_quantity':
            case 'max_quantity':
                $query->orderByDesc('products.quantity')->orderByDesc('products.id');
                break;
            case 'quantity_asc':
            case 'least_quantity':
            case 'min_quantity':
                $query->orderBy('products.quantity')->orderByDesc('products.id');
                break;
            case 'profit_desc':
            case 'max_profit':
                $query->orderByRaw("{$profitSql} DESC")->orderByDesc('products.id');
                break;
            case 'profit_asc':
            case 'min_profit':
                $query->orderByRaw("{$profitSql} ASC")->orderByDesc('products.id');
                break;
            case 'profit_percent_desc':
            case 'most_profit_percent':
            case 'max_profit_percent':
                $query->orderByRaw("{$profitPercentSql} DESC")->orderByDesc('products.id');
                break;
            case 'profit_percent_asc':
            case 'least_profit_percent':
            case 'min_profit_percent':
                $query->orderByRaw("{$profitPercentSql} ASC")->orderByDesc('products.id');
                break;
            case 'discount_desc':
            case 'max_discount':
                $query->orderByRaw("{$discountAmountSql} DESC")->orderByDesc('products.id');
                break;
            case 'discount_asc':
            case 'min_discount':
                $query->orderByRaw("{$discountAmountSql} ASC")->orderByDesc('products.id');
                break;
            case 'discount_percent_desc':
            case 'most_discount_percent':
            case 'max_discount_percent':
                $query->orderByRaw("{$discountPercentSql} DESC")->orderByDesc('products.id');
                break;
            case 'discount_percent_asc':
            case 'least_discount_percent':
            case 'min_discount_percent':
                $query->orderByRaw("{$discountPercentSql} ASC")->orderByDesc('products.id');
                break;
            // most_profit / least_profit = مبلغ سود (نه درصد) — عمداً جدا از profit_percent
            case 'most_profit':
                $query->orderByRaw("{$profitSql} DESC")->orderByDesc('products.id');
                break;
            case 'least_profit':
                $query->orderByRaw("{$profitSql} ASC")->orderByDesc('products.id');
                break;
            case 'most_discount':
                $query->orderByRaw("{$discountAmountSql} DESC")->orderByDesc('products.id');
                break;
            case 'least_discount':
                $query->orderByRaw("{$discountAmountSql} ASC")->orderByDesc('products.id');
                break;
            default:
                $query->orderByDesc('products.id');
                break;
        }
    }

    /**
     * کالاهای تولیدی همین فروشگاه برای لیست product و product-all.
     *
     * @param  mixed  $searchDataModel
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function producedGoodsForProductAll(Request $request, int $atelierId, $searchDataModel, ?int $categoryId = null)
    {
        if (! Schema::hasTable('produced_goods')) {
            return collect();
        }

        $query = ProducedGood::query()
            ->where('atelier_id', $atelierId)
            ->with('ingredients.rawMaterial')
            ->orderBy('name');

        if (Schema::hasTable('category_produced_good')) {
            $query->with('categories');
            if ($categoryId) {
                $query->whereHas('categories', function ($q) use ($categoryId) {
                    $q->where('categories.id', $categoryId);
                });
            }
        } elseif ($categoryId) {
            return collect();
        }

        if ($searchDataModel) {
            $query->where(function ($q) use ($searchDataModel) {
                if (is_object($searchDataModel) && isset($searchDataModel->name) && $searchDataModel->name !== '') {
                    $q->where('name', 'like', '%'.$searchDataModel->name.'%');
                } elseif (is_string($searchDataModel) && $searchDataModel !== '') {
                    $q->where('name', 'like', '%'.$searchDataModel.'%');
                }
            });
        }

        $costService = app(ProducedGoodCostService::class);

        return $query->get()->map(function (ProducedGood $good) use ($costService) {
            $costService->attachCost($good, 1.0, true);

            return $this->formatProducedGoodCatalogItem($good);
        })->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatProducedGoodCatalogItem(ProducedGood $good): array
    {
        $good->loadMissing('ingredients.rawMaterial');
        if (Schema::hasTable('category_produced_good')) {
            $good->loadMissing('categories');
        }

        $salePrice = (float) $good->sale_price;
        $original = Schema::hasColumn('produced_goods', 'original_sale_price')
            ? (float) ($good->original_sale_price ?? $salePrice)
            : $salePrice;
        if ($original <= 0) {
            $original = $salePrice;
        }
        $discountAmount = max(0, $original - $salePrice);
        $discountPercent = $original > 0 ? ($discountAmount / $original) * 100 : 0;
        $purchasePrice = (float) ($good->cost_per_kg ?? 0);
        $unitProfit = $salePrice - $purchasePrice;

        return [
            'id' => $good->id,
            'item_type' => 'produced_good',
            'produced_good_id' => $good->id,
            'product_id' => null,
            'name' => $good->name,
            'sale_price' => $salePrice,
            'original_sale_price' => $original,
            'purchase_price' => $purchasePrice,
            'cost_per_kg' => $purchasePrice,
            'profit_per_kg' => (float) ($good->profit_per_kg ?? $unitProfit),
            'profit_percent' => $good->profit_percent,
            'unit_profit' => round($unitProfit, 2),
            'discount_percent' => round($discountPercent, 2),
            'discount_amount' => round($discountAmount, 2),
            'has_discount' => $discountPercent > 0,
            'quantity' => (float) ($good->stock_kg ?? 0),
            'unit_type' => Product::UNIT_KG,
            'unit_label' => ProductQuantityTools::unitLabel(Product::UNIT_KG),
            'price_unit_label' => ProductQuantityTools::priceUnitLabel(Product::UNIT_KG),
            'barcode' => null,
            'images' => [],
            'categories' => Schema::hasTable('category_produced_good') ? ($good->categories ?? []) : [],
            'atelier_id' => $good->atelier_id,
            'note' => $good->note,
        ];
    }

    private function resolveShopProducedGood(Request $request, $id): ?ProducedGood
    {
        if (! Schema::hasTable('produced_goods')) {
            return null;
        }

        $atelierId = $this->shopAtelierIdOrAbort($request);
        $query = ProducedGood::query()
            ->where('id', $id)
            ->where('atelier_id', $atelierId)
            ->with(['ingredients.rawMaterial']);
        if (Schema::hasTable('category_produced_good')) {
            $query->with('categories');
        }
        $good = $query->first();
        if (! $good) {
            return null;
        }
        app(ProducedGoodCostService::class)->attachCost($good, 1.0, true);

        return $good;
    }

    /**
     * @param  \Illuminate\Support\Collection  $items
     */
    private function paginateMergedCatalog($items, Request $request, int $perPage): LengthAwarePaginator
    {
        $page = max(1, (int) $request->input('page', 1));
        $total = $items->count();
        $slice = $items->forPage($page, $perPage)->values();

        return new LengthAwarePaginator($slice, $total, $perPage, $page, [
            'path' => url()->current(),
            'query' => $request->query(),
        ]);
    }

    /**
     * فیلدهای محاسباتی سود و تخفیف برای پاسخ API.
     */
    private function appendProductPricingMeta(Product $product): Product
    {
        if ($product->original_sale_price === null) {
            $product->original_sale_price = $product->sale_price;
        }

        $discountPercent = 0;
        $discountAmount = 0;
        if ($product->original_sale_price > 0 && $product->sale_price < $product->original_sale_price) {
            $discountAmount = $product->original_sale_price - $product->sale_price;
            $discountPercent = ($discountAmount / $product->original_sale_price) * 100;
        }

        $purchasePrice = (float) $product->purchase_price;
        $salePrice = (float) $product->sale_price;
        $unitProfit = $salePrice - $purchasePrice;

        $product->unit_profit = round($unitProfit, 2);
        $product->profit_percent = $purchasePrice > 0
            ? round(($unitProfit / $purchasePrice) * 100, 2)
            : 0.0;
        $product->discount_percent = round($discountPercent, 2);
        $product->discount_amount = round((float) $discountAmount, 2);
        $product->has_discount = $discountPercent > 0;

        $unitType = $product->unit_type ?? Product::UNIT_PIECE;
        $product->unit_type = $unitType;
        $product->unit_label = ProductQuantityTools::unitLabel($unitType);
        $product->price_unit_label = ProductQuantityTools::priceUnitLabel($unitType);

        return $product;
    }

}
