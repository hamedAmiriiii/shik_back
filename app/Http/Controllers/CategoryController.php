<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProducedGood;
use App\Services\ProducedGoodCostService;
use App\Tools\ImageTools;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    /**
     * نمایش لیست کتگوری‌ها (به صورت درختی)
     */
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $query = Category::query()->where('atelier_id', $atelierId);

        // فیلتر بر اساس فعال/غیرفعال
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // فیلتر فقط کتگوری‌های ریشه (بدون والد)
        if ($request->boolean('roots_only')) {
            $query->whereNull('parent_id');
        }

        // فیلتر بر اساس والد
        if ($request->has('parent_id')) {
            $query->where('parent_id', $request->input('parent_id'));
        }

        // مرتب‌سازی بر اساس order و name
        $query->orderBy('order')->orderBy('name');

        // اگر درختی بخواهیم (با فرزندان)
        if ($request->boolean('tree')) {
            $categories = $query->whereNull('parent_id')
                ->with('children.children')
                ->get();
            
            return response($this->buildTree($categories));
        }

        // لیست ساده
        $categories = $query->with('parent')->get();

        return response($categories);
    }

    /**
     * ساخت ساختار درختی از کتگوری‌ها
     */
    private function buildTree($categories)
    {
        return $categories->map(function ($category) {
            $item = [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'image' => $category->image,
                'image_url' => $category->image_url,
                'parent_id' => $category->parent_id,
                'order' => $category->order,
                'is_active' => $category->is_active,
                'full_path' => $category->full_path,
                'children' => [],
            ];

            if ($category->children->count() > 0) {
                $item['children'] = $this->buildTree($category->children);
            }

            return $item;
        });
    }

    /**
     * دریافت لیست تمام کتگوری‌ها به صورت flat (بدون ساختار درختی)
     */
    public function getAll(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $categories = Category::where('atelier_id', $atelierId)->orderBy('order')->orderBy('name')->get();

        return response($categories);
    }

    /**
     * افزودن کتگوری جدید
     */
    public function store(Request $request)
    {
        $atelierId = $this->staffShopAtelierId($request);

        $slugUnique = Rule::unique('categories', 'slug');
        if ($atelierId !== null) {
            $slugUnique = $slugUnique->where('atelier_id', $atelierId);
        } else {
            $slugUnique = $slugUnique->whereNull('atelier_id');
        }

        $fields = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => ['nullable', 'string', 'max:255', $slugUnique],
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:categories,id',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'image' => 'nullable|string',
        ]);

        if ($atelierId !== null) {
            $fields['atelier_id'] = $atelierId;
            if (! empty($fields['parent_id'])) {
                $parent = Category::where('id', $fields['parent_id'])->where('atelier_id', $atelierId)->first();
                if (! $parent) {
                    return response(['error' => 'دستهٔ والد متعلق به این فروشگاه نیست'], 422);
                }
            }
        }

        // اگر slug داده نشده، از name ایجاد می‌کنیم
        if (empty($fields['slug'])) {
            $fields['slug'] = Str::slug($fields['name']);

            // اطمینان از یکتا بودن slug
            $originalSlug = $fields['slug'];
            $counter = 1;
            $slugQuery = Category::where('slug', $fields['slug']);
            if ($atelierId !== null) {
                $slugQuery->where('atelier_id', $atelierId);
            } else {
                $slugQuery->whereNull('atelier_id');
            }
            while ($slugQuery->exists()) {
                $fields['slug'] = $originalSlug . '-' . $counter;
                $counter++;
                $slugQuery = Category::where('slug', $fields['slug']);
                if ($atelierId !== null) {
                    $slugQuery->where('atelier_id', $atelierId);
                } else {
                    $slugQuery->whereNull('atelier_id');
                }
            }
        }

        // مقادیر پیش‌فرض
        if (!isset($fields['order'])) {
            $fields['order'] = 0;
        }
        if (!isset($fields['is_active'])) {
            $fields['is_active'] = true;
        }

        $imageData = $fields['image'] ?? null;
        unset($fields['image']);

        $category = Category::create($fields);
        if (! empty($imageData)) {
            $this->saveCategoryImage($category, $imageData);
        }
        $category->load('parent');

        return response($category, 201);
    }

    /**
     * یافتن دسته‌بندی متعلق به فروشگاه جاری (مسیر api/{shop}/category/{id}).
     */
    protected function resolveShopCategory(Request $request, $categoryId): Category
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $category = Category::query()->where('id', $categoryId)->first();
        if (! $category) {
            abort(response()->json(['message' => 'دسته‌بندی یافت نشد'], 404));
        }

        if ($category->atelier_id !== null && (int) $category->atelier_id !== $atelierId) {
            abort(response()->json(['message' => 'دسته‌بندی یافت نشد'], 404));
        }

        // دادهٔ قدیمی: atelier_id خالی — اگر محصولی از همین فروشگاه در دسته باشد مجاز است
        if ($category->atelier_id === null) {
            $hasShopProduct = $category->products()
                ->where('products.atelier_id', $atelierId)
                ->exists();

            if (! $hasShopProduct) {
                abort(response()->json(['message' => 'دسته‌بندی یافت نشد'], 404));
            }
        }

        return $category;
    }

    protected function categoryAtelierIdForShop(Category $category, Request $request): int
    {
        if ($category->atelier_id !== null) {
            return (int) $category->atelier_id;
        }

        return $this->shopAtelierIdOrAbort($request);
    }

    /**
     * نمایش جزئیات یک کتگوری
     */
    public function show(Request $request, $category, string $shop = '')
    {
        $category = $this->resolveShopCategory($request, $category);

        $atelierId = $this->categoryAtelierIdForShop($category, $request);
        $category->load([
            'parent',
            'children',
            'products' => function ($query) use ($atelierId) {
                $query->where('products.atelier_id', $atelierId)
                    ->with(['images']);
            },
        ]);

        return response($category);
    }

    /**
     * ویرایش کتگوری
     */
    public function update(Request $request, Category $category)
    {
        $this->assertModelBelongsToStaffAtelier($request, $category);

        $atelierId = $this->staffShopAtelierId($request);
        $slugUnique = Rule::unique('categories', 'slug')->ignore($category->id);
        if ($atelierId !== null) {
            $slugUnique = $slugUnique->where('atelier_id', $atelierId);
        } else {
            $slugUnique = $slugUnique->whereNull('atelier_id');
        }

        $fields = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => ['nullable', 'string', 'max:255', $slugUnique],
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:categories,id',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'image' => 'nullable|string',
        ]);

        if ($atelierId !== null && ! empty($fields['parent_id'])) {
            $parent = Category::where('id', $fields['parent_id'])->where('atelier_id', $atelierId)->first();
            if (! $parent) {
                return response(['error' => 'دستهٔ والد متعلق به این فروشگاه نیست'], 422);
            }
        }

        // جلوگیری از ایجاد حلقه در ساختار درختی (کتگوری نمی‌تواند والد خودش باشد)
        if (isset($fields['parent_id']) && $fields['parent_id'] == $category->id) {
            return response(['error' => 'کتگوری نمی‌تواند والد خودش باشد'], 422);
        }

        // جلوگیری از ایجاد حلقه: اگر parent_id برابر یکی از فرزندان باشد
        $descendants = $category->descendants()->pluck('id')->toArray();
        if (isset($fields['parent_id']) && in_array($fields['parent_id'], $descendants)) {
            return response(['error' => 'کتگوری نمی‌تواند والد خودش یا یکی از فرزندانش باشد'], 422);
        }

        // اگر slug داده نشده، از name ایجاد می‌کنیم
        if (empty($fields['slug'])) {
            $fields['slug'] = Str::slug($fields['name']);
            
            // اطمینان از یکتا بودن slug
            $originalSlug = $fields['slug'];
            $counter = 1;
            while (Category::where('slug', $fields['slug'])->where('id', '!=', $category->id)
                ->when($atelierId !== null, function ($q) use ($atelierId) {
                    $q->where('atelier_id', $atelierId);
                })
                ->when($atelierId === null, function ($q) {
                    $q->whereNull('atelier_id');
                })
                ->exists()) {
                $fields['slug'] = $originalSlug . '-' . $counter;
                $counter++;
            }
        }

        $imageData = array_key_exists('image', $fields) ? $fields['image'] : null;
        unset($fields['image']);

        $category->update($fields);

        if ($request->exists('image')) {
            if (empty($imageData)) {
                $this->deleteCategoryImage($category);
                $category->image = null;
                $category->save();
            } else {
                $this->saveCategoryImage($category, $imageData);
            }
        }

        $category->load('parent');

        return response($category);
    }

    /**
     * حذف کتگوری
     */
    public function destroy(Request $request, Category $category)
    {
        $this->assertModelBelongsToStaffAtelier($request, $category);
        // اگر کتگوری دارای فرزند است، نمی‌توان حذف کرد (یا باید ابتدا فرزندان را حذف کرد)
        if ($category->children()->count() > 0) {
            return response([
                'error' => 'این کتگوری دارای زیرکتگوری است. لطفاً ابتدا زیرکتگوری‌ها را حذف کنید.'
            ], 422);
        }

        $this->deleteCategoryImage($category);
        $category->delete();
        return response(['message' => 'کتگوری با موفقیت حذف شد']);
    }

    /**
     * دریافت فرزندان یک کتگوری
     */
    public function children(Request $request, $category, string $shop = '')
    {
        $category = $this->resolveShopCategory($request, $category);

        $children = $category->children()->with('children')->get();
        return response($children);
    }

    /**
     * دریافت محصولات یک کتگوری (با pagination و جستجو)
     * شامل محصولات زیرمجموعه‌ها نیز می‌شود
     */
    public function products(Request $request, $category, string $shop = '')
    {
        $category = $this->resolveShopCategory($request, $category);
        $atelierId = $this->categoryAtelierIdForShop($category, $request);

        // دریافت تمام IDهای زیرمجموعه‌ها (شامل خود category)
        $categoryIds = $category->getAllDescendantIds();

        // دریافت محصولاتی که به این category یا زیرمجموعه‌هایش تعلق دارند
        $query = Product::where('atelier_id', $atelierId)->whereHas('categories', function ($q) use ($categoryIds) {
            $q->whereIn('categories.id', $categoryIds);
        });
        
        // جستجو بر اساس searchFilterModel
        $searchDataModel = json_decode($request->input('searchFilterModel'));
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
        
        $perPage = max(1, (int) $request->input('per_page', 10));

        $products = $query->with(['images', 'categories'])->orderBy('id', 'desc')->get()->map(function ($product) {
            $product->item_type = 'product';
            $product->product_id = $product->id;
            $product->produced_good_id = null;
            if ($product->original_sale_price === null) {
                $product->original_sale_price = $product->sale_price;
            }
            $discountPercent = 0;
            $discountAmount = 0;
            if ($product->original_sale_price > 0 && $product->sale_price < $product->original_sale_price) {
                $discountAmount = $product->original_sale_price - $product->sale_price;
                $discountPercent = ($discountAmount / $product->original_sale_price) * 100;
            }
            $product->discount_percent = round($discountPercent, 2);
            $product->discount_amount = $discountAmount;
            $product->has_discount = $discountPercent > 0;

            return $product;
        });

        $produced = $this->producedGoodsForCategory($atelierId, $categoryIds, $searchDataModel);
        $merged = $produced->concat($products)->values();
        $page = max(1, (int) $request->input('page', 1));
        $paginator = new LengthAwarePaginator(
            $merged->forPage($page, $perPage)->values(),
            $merged->count(),
            $perPage,
            $page,
            ['path' => url()->current(), 'query' => $request->query()]
        );

        return response($paginator);
    }

    /**
     * @param  array<int, int>  $categoryIds
     * @param  mixed  $searchDataModel
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function producedGoodsForCategory(int $atelierId, array $categoryIds, $searchDataModel)
    {
        if (! Schema::hasTable('produced_goods') || ! Schema::hasTable('category_produced_good')) {
            return collect();
        }

        $query = ProducedGood::query()
            ->where('atelier_id', $atelierId)
            ->whereHas('categories', function ($q) use ($categoryIds) {
                $q->whereIn('categories.id', $categoryIds);
            })
            ->with(['ingredients.rawMaterial', 'categories'])
            ->orderBy('name');

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
            $salePrice = (float) $good->sale_price;
            $original = Schema::hasColumn('produced_goods', 'original_sale_price')
                ? (float) ($good->original_sale_price ?? $salePrice)
                : $salePrice;
            $discountAmount = max(0, $original - $salePrice);
            $discountPercent = $original > 0 ? ($discountAmount / $original) * 100 : 0;

            return [
                'id' => $good->id,
                'item_type' => 'produced_good',
                'produced_good_id' => $good->id,
                'product_id' => null,
                'name' => $good->name,
                'sale_price' => $salePrice,
                'original_sale_price' => $original,
                'purchase_price' => (float) $good->cost_per_kg,
                'quantity' => (float) $good->stock_kg,
                'unit_type' => 'kg',
                'discount_percent' => round($discountPercent, 2),
                'discount_amount' => round($discountAmount, 2),
                'has_discount' => $discountPercent > 0,
                'images' => [],
                'categories' => $good->categories,
                'atelier_id' => $good->atelier_id,
            ];
        })->values();
    }

    private function saveCategoryImage(Category $category, string $imageData): void
    {
        $imageString = $imageData;
        if (strpos($imageData, ',') !== false) {
            $parts = explode(',', $imageData);
            $imageString = $parts[1];
        }

        $imageContent = base64_decode($imageString);
        if ($imageContent === false) {
            return;
        }

        $this->deleteCategoryImage($category);

        $imagePath = ImageTools::saveFile(
            "/categories/{$category->id}/image_" . time() . ".jpeg",
            $imageContent
        );

        $category->image = $imagePath;
        $category->save();
    }

    private function deleteCategoryImage(Category $category): void
    {
        $originalPath = $category->getOriginal('image') ?: $category->image;
        if ($originalPath && Storage::exists('public/' . $originalPath)) {
            Storage::delete('public/' . $originalPath);
        }
    }
}

