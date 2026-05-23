<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductImage;
use App\Tools\ImageTools;
use Illuminate\Http\Request;
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

        $sort = $this->resolveProductListSort($request);
        $this->applyProductListSort($query, $sort);

        $perPage = $request->input('per_page', 10);

        $products = $query->with(['images', 'categories', 'manufacturer'])->paginate($perPage);

        $products->getCollection()->transform(function ($product) {
            return $this->appendProductPricingMeta($product);
        });

        $products->withPath(url()->current());
        $products->appends($request->only(['sort', 'order_by', 'search', 'searchFilterModel', 'per_page']));

        return response($products)->header('X-Applied-Sort', $sort !== '' ? $sort : 'id_desc');
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

        $fields = $request->validate([
            "name" => "required|string|max:255",
            "purchase_price" => "required|numeric|min:0",
            "sale_price" => "required|numeric|min:0",
            "quantity" => "required|integer|min:0",
            'barcode' => ['nullable', 'string', 'min:1', 'max:255', $this->uniqueBarcodeRule($atelierId)],
            'original_sale_price' => 'nullable|numeric|min:0',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'manufacturer_id' => 'nullable|exists:manufacturers,id',
            'images' => 'nullable|array',
            'images.*' => 'nullable|string',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'exists:categories,id',
            'sizes' => 'nullable|array', // لیست سایزها
            'sizes.*' => 'string',
            'colors' => 'nullable|array', // لیست رنگ‌ها
            'colors.*' => 'string',
        ]);

        // محاسبه قیمت با تخفیف در صورت وجود
        if (isset($fields['discount_percent']) && $fields['discount_percent'] > 0) {
            // اگر original_sale_price داده شده از آن استفاده کن، در غیر این صورت از sale_price
            $basePrice = $fields['original_sale_price'] ?? $fields['sale_price'];
            
            // محاسبه تخفیف
            $discountAmount = ($basePrice * $fields['discount_percent']) / 100;
            $priceAfterDiscount = max(0, $basePrice - $discountAmount);
            
            // رند کردن به عدد فرد که دهگان و صدگانش 0 باشد
            $fields['sale_price'] = $this->roundToOddWithZeroEnding($priceAfterDiscount);
            $fields['original_sale_price'] = $basePrice;
            unset($fields['discount_percent']); // حذف از fields چون در دیتابیس نیست
        } else {
            // اگر original_sale_price ارسال نشده باشد، آن را برابر sale_price قرار بده
            if (!isset($fields['original_sale_price'])) {
                $fields['original_sale_price'] = $fields['sale_price'];
            }
        }

        $sellerBarcode = isset($fields['barcode']) && $fields['barcode'] !== ''
            ? (string) $fields['barcode']
            : null;

        if ($sellerBarcode !== null) {
            $fields['barcode'] = $sellerBarcode;
        } else {
            $fields['barcode'] = $this->generateTemporaryBarcode($atelierId);
        }

        $fields['atelier_id'] = $atelierId;
        $product = Product::create($fields);

        if ($sellerBarcode === null) {
            $product->barcode = (string) $product->id;
            $product->save();
        }

        // ذخیره عکس‌ها
        if ($request->has('images') && is_array($request->images)) {
            $this->saveProductImages($product, $request->images);
        }

        // اتصال کتگوری‌ها
        if ($request->has('category_ids') && is_array($request->category_ids)) {
            $product->categories()->sync($request->category_ids);
        }

        // بارگذاری مجدد محصول با عکس‌ها و کتگوری‌ها
        $product->load(['images', 'categories']);

        return response($product, 201);
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
        
        $products = $query->with(['images', 'categories', 'manufacturer'])->orderBy('id', 'desc')->get();
        
        // اضافه کردن اطلاعات تخفیف به هر محصول
        $products->transform(function ($product) {
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
        
        return response($products);
    }

    /**
     * نمایش جزئیات یک محصول
     */
    public function show(Request $request, Product $product)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if ((int) $product->atelier_id !== $atelierId) {
            return response(['message' => 'محصول یافت نشد'], 404);
        }

        $product->load(['images', 'categories', 'manufacturer']);
        return response($product);
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
     * ویرایش اطلاعات محصول
     */
    public function update(Request $request, Product $product)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            abort(response()->json([
                'message' => 'ویرایش محصول فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422));
        }
        if ((int) $product->atelier_id !== $atelierId) {
            return response(['message' => 'محصول یافت نشد'], 404);
        }

        if ($request->has('barcode')) {
            $request->merge(['barcode' => trim((string) $request->input('barcode'))]);
        }

        $fields = $request->validate([
            "name" => "required|string|max:255",
            "purchase_price" => "required|numeric|min:0",
            "sale_price" => "required|numeric|min:0",
            "quantity" => "required|integer|min:0",
            'barcode' => ['required', 'string', 'min:1', 'max:255', $this->uniqueBarcodeRule($atelierId, $product->id)],
            'original_sale_price' => 'nullable|numeric|min:0',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'manufacturer_id' => 'nullable|exists:manufacturers,id',
            'images' => 'nullable|array',
            'images.*' => 'nullable|string',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'exists:categories,id',
            'sizes' => 'nullable|array', // لیست سایزها
            'sizes.*' => 'string',
            'colors' => 'nullable|array', // لیست رنگ‌ها
            'colors.*' => 'string',
        ]);

        // محاسبه قیمت با تخفیف در صورت وجود
        if (isset($fields['discount_percent'])) {
            if ($fields['discount_percent'] >= 0) {
                // اگر original_sale_price داده شده از آن استفاده کن، در غیر این صورت از original_sale_price موجود یا sale_price
               $basePrice = $fields['sale_price'] ?? $fields['sale_price'] ?? $product->original_sale_price ;
                
                // محاسبه تخفیف
                $discountAmount = ($basePrice * $fields['discount_percent']) / 100;
                $priceAfterDiscount = max(0, $basePrice - $discountAmount);
                
                // رند کردن به عدد فرد که دهگان و صدگانش 0 باشد
                $fields['sale_price'] = $this->roundToOddWithZeroEnding($priceAfterDiscount);
                $fields['original_sale_price'] = $basePrice;
            } 
            unset($fields['discount_percent']); // حذف از fields چون در دیتابیس نیست
        } else {
            // اگر original_sale_price ارسال نشده باشد، از original_sale_price موجود استفاده کن
            if (!isset($fields['original_sale_price'])) {
                // اگر محصول original_sale_price دارد، آن را حفظ کن
                if ($product->original_sale_price !== null) {
                    $fields['original_sale_price'] = $product->original_sale_price;
                } else {
                    // اگر ندارد، برابر sale_price قرار بده
                    $fields['original_sale_price'] = $fields['sale_price'];
                }
            }
        }

        $product->update($fields);

        // مدیریت عکس‌ها - اضافه کردن به عکس‌های قبلی
        if ($request->has('images') && is_array($request->images) && !empty($request->images)) {
            // فقط عکس‌های جدید را اضافه می‌کنیم (جایگزین نمی‌کنیم)
            $this->saveProductImages($product, $request->images);
        }

        // مدیریت کتگوری‌ها
        if ($request->has('category_ids')) {
            if (is_array($request->category_ids) && !empty($request->category_ids)) {
                $product->categories()->sync($request->category_ids);
            } else {
                // اگر آرایه خالی باشد، تمام کتگوری‌ها را حذف می‌کنیم
                $product->categories()->detach();
            }
        }

        // بارگذاری مجدد محصول با عکس‌ها و کتگوری‌ها
        $product->load(['images', 'categories']);

        return response($product);
    }

    /**
     * حذف از لیست (soft delete) — رکورد و سابقه خرید در دیتابیس می‌ماند.
     */
    public function destroy(Request $request, Product $product)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            abort(response()->json([
                'message' => 'حذف محصول فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422));
        }
        if ((int) $product->atelier_id !== $atelierId) {
            return response(['message' => 'محصول یافت نشد'], 404);
        }

        $product->delete();

        return response(['message' => 'محصول از لیست حذف شد. سابقه فروش حفظ شده است.']);
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
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => 'required|exists:products,id',
            'discount_percent' => 'required|numeric|min:0|max:100',
        ]);

        $productIds = $request->input('product_ids');
        $discountPercent = $request->input('discount_percent');

        // دریافت همه محصولات همین فروشگاه
        $products = Product::whereIn('id', $productIds)->where('atelier_id', $atelierId)->get();

        if ($products->isEmpty()) {
            return response(['error' => 'محصولی یافت نشد'], 404);
        }

        if ($products->count() !== count(array_unique($productIds))) {
            return response(['error' => 'برخی شناسه‌ها متعلق به این فروشگاه نیستند'], 422);
        }

        $updatedProducts = [];
        foreach ($products as $product) {
            // استفاده از original_sale_price اگر وجود داشته باشد، در غیر این صورت از sale_price
            $baseSalePrice = $product->original_sale_price ?? $product->sale_price;
            
            // ذخیره original_sale_price اگر null باشد
            if ($product->original_sale_price === null) {
                $product->original_sale_price = $product->sale_price;
            }
            
            // محاسبه تخفیف بر اساس original_sale_price
            $discountAmount = ($baseSalePrice * $discountPercent) / 100;
            $priceAfterDiscount = max(0, $baseSalePrice - $discountAmount);
            
            // رند کردن به عدد فرد که دهگان و صدگانش 0 باشد
            $newSalePrice = $this->roundToOddWithZeroEnding($priceAfterDiscount);
            
            // به‌روزرسانی sale_price (قیمت با تخفیف)
            $product->sale_price = $newSalePrice;
            $product->save();

            $updatedProducts[] = [
                'id' => $product->id,
                'name' => $product->name,
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
    public function deleteImage(Request $request, Product $product, $imageId)
    {
        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            abort(response()->json([
                'message' => 'حذف تصویر فقط با حساب پرسنل متصل به فروشگاه امکان‌پذیر است.',
            ], 422));
        }
        if ((int) $product->atelier_id !== $atelierId) {
            return response(['message' => 'محصول یافت نشد'], 404);
        }

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

        return $product;
    }

    /**
     * رند کردن به عدد فرد که دهگان و صدگانش 0 باشد
     * (همان متد در PurchasedProductController)
     */
    private function roundToOddWithZeroEnding($number)
    {
        if ($number <= 0) {
            return 0;
        }
    
        $baseThousand = floor($number / 1000);
    
        // اگر زوج بود، یکی کم کن تا فرد شود
        if ($baseThousand % 2 === 0) {
            $lowerOdd = $baseThousand - 1;
        } else {
            $lowerOdd = $baseThousand;
        }
    
        // فرد بعدی
        $upperOdd = $lowerOdd + 2;
    
        $lowerValue = $lowerOdd * 1000;
        $upperValue = $upperOdd * 1000;
    
        // انتخاب نزدیک‌ترین
        return (abs($number - $lowerValue) <= abs($number - $upperValue))
            ? $lowerValue
            : $upperValue;
    }
}
