<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
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

        $category = Category::create($fields);
        $category->load('parent');

        return response($category, 201);
    }

    /**
     * نمایش جزئیات یک کتگوری
     */
    public function show(Request $request, Category $category)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if ((int) $category->atelier_id !== $atelierId) {
            return response(['message' => 'یافت نشد'], 404);
        }

        $category->load(['parent', 'children', 'products']);
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

        $category->update($fields);
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

        $category->delete();
        return response(['message' => 'کتگوری با موفقیت حذف شد']);
    }

    /**
     * دریافت فرزندان یک کتگوری
     */
    public function children(Request $request, Category $category)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if ((int) $category->atelier_id !== $atelierId) {
            return response(['message' => 'یافت نشد'], 404);
        }

        $children = $category->children()->with('children')->get();
        return response($children);
    }

    /**
     * دریافت محصولات یک کتگوری (با pagination و جستجو)
     * شامل محصولات زیرمجموعه‌ها نیز می‌شود
     */
    public function products(Request $request, Category $category)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if ((int) $category->atelier_id !== $atelierId) {
            return response(['message' => 'یافت نشد'], 404);
        }

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
        
        // دریافت تعداد آیتم در هر صفحه از request (پیش‌فرض 10)
        $perPage = $request->input('per_page', 10);
        
        $products = $query->with(['images', 'categories'])->orderBy('id', 'desc')->paginate($perPage);
        
        // اضافه کردن اطلاعات تخفیف به هر محصول
        $products->getCollection()->transform(function ($product) {
            // اگر original_sale_price null باشد، آن را برابر sale_price قرار بده
            if ($product->original_sale_price === null) {
                $product->original_sale_price = $product->sale_price;
            }
            
            // محاسبه درصد تخفیف
            $discountPercent = 0;
            $discountAmount = 0;
            if ($product->original_sale_price > 0 && $product->sale_price < $product->original_sale_price) {
                $discountAmount = $product->original_sale_price - $product->sale_price;
                $discountPercent = ($discountAmount / $product->original_sale_price) * 100;
            }
            
            // اضافه کردن فیلدهای محاسبه شده
            $product->discount_percent = round($discountPercent, 2);
            $product->discount_amount = $discountAmount;
            $product->has_discount = $discountPercent > 0;
            
            return $product;
        });
        
        // حفظ مسیر URL برای pagination
        $products->withPath(url()->current());
        
        return response($products);
    }
}

