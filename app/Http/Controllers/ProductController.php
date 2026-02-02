<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductImage;
use App\Tools\ImageTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use http\Env\Response;

class ProductController extends Controller
{
    /**
     * نمایش لیست محصولات
     */
    public function index(Request $request)
    {
        $query = Product::query();
        
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
        
        // دریافت تعداد آیتم در هر صفحه از request (پیش‌فرض 50)
        $perPage = $request->input('per_page', 10);
        
        $products = $query->with(['images', 'categories', 'manufacturer'])->orderBy('id', 'desc')->paginate($perPage);
        
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

    /**
     * افزودن محصول جدید
     */
    public function store(Request $request)
    {
        $fields = $request->validate([
            "name" => "required|string|max:255",
            "purchase_price" => "required|numeric|min:0",
            "sale_price" => "required|numeric|min:0",
            "quantity" => "required|integer|min:0",
            'barcode' => 'nullable|string|unique:products|max:255',
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

        // اگر بارکد ارسال نشده باشد، ابتدا یک بارکد موقت ایجاد می‌کنیم
        // سپس بعد از ایجاد محصول، آن را به ID تغییر می‌دهیم
        $hasBarcode = !empty($fields['barcode']);
        if (!$hasBarcode) {
            // ایجاد یک بارکد موقت برای ایجاد محصول
            $fields['barcode'] = $this->generateTemporaryBarcode();
        }

        $product = Product::create($fields);

        // اگر بارکد ارسال نشده بود، آن را به ID محصول تغییر می‌دهیم
        if (!$hasBarcode) {
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
    private function generateTemporaryBarcode()
    {
        do {
            // استفاده از timestamp و عدد تصادفی برای تولید بارکد موقت
            $barcode = 'TMP' . time() . rand(10000, 99999);
        } while (Product::where('barcode', $barcode)->exists());

        return $barcode;
    }

    /**
     * دریافت تمام محصولات بدون pagination
     */
    public function getAll(Request $request)
    {
        $query = Product::query();
        
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
    public function show(Product $product)
    {
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
        $limit = (int)$request->input('limit', 10); // پیش‌فرض 10 محصول
        
        // محاسبه تعداد فروش هر محصول
        $productIds = DB::table('purchased_products')
            ->select('product_id', DB::raw('SUM(quantity) as total_sold'))
            ->groupBy('product_id')
            ->orderBy('total_sold', 'desc')
            ->limit($limit)
            ->pluck('product_id')
            ->toArray();
        
        // اگر هیچ فروشی وجود نداشت، تمام محصولات را برمی‌گردانیم
        if (empty($productIds)) {
            $bestSellingProducts = Product::with(['images', 'categories', 'manufacturer'])
                ->limit($limit)
                ->get();
            
            // اضافه کردن total_sold = 0 به هر محصول
            $bestSellingProducts->each(function($product) {
                $product->total_sold = 0;
            });
        } else {
            // محاسبه total_sold برای هر محصول
            $totalSoldMap = DB::table('purchased_products')
                ->select('product_id', DB::raw('SUM(quantity) as total_sold'))
                ->whereIn('product_id', $productIds)
                ->groupBy('product_id')
                ->pluck('total_sold', 'product_id')
                ->toArray();
            
            // دریافت محصولات بر اساس ترتیب فروش
            $products = Product::whereIn('id', $productIds)
                ->with(['images', 'categories', 'manufacturer'])
                ->get();
            
            // مرتب‌سازی بر اساس ترتیب productIds و اضافه کردن total_sold
            $bestSellingProducts = collect($productIds)->map(function($productId) use ($products, $totalSoldMap) {
                $product = $products->firstWhere('id', $productId);
                if ($product) {
                    $product->total_sold = $totalSoldMap[$productId] ?? 0;
                    return $product;
                }
                return null;
            })->filter()->values();
        }

        // اضافه کردن اطلاعات تخفیف به هر محصول
        $bestSellingProducts->transform(function ($product) {
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

        return response($bestSellingProducts, 200);
    }

    /**
     * ویرایش اطلاعات محصول
     */
    public function update(Request $request, Product $product)
    {
        $fields = $request->validate([
            "name" => "required|string|max:255",
            "purchase_price" => "required|numeric|min:0",
            "sale_price" => "required|numeric|min:0",
            "quantity" => "required|integer|min:0",
            'barcode' => 'required|string|unique:products,barcode,' . $product->id . '|max:255',
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
     * حذف محصول
     */
    public function destroy(Product $product)
    {
        // حذف عکس‌های محصول
        $this->deleteProductImages($product);
        
        $product->delete();
        return response(['message' => 'محصول با موفقیت حذف شد']);
    }

    /**
     * اعمال تخفیف به چند محصول
     */
    public function applyDiscount(Request $request)
    {
        $request->validate([
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => 'required|exists:products,id',
            'discount_percent' => 'required|numeric|min:0|max:100',
        ]);

        $productIds = $request->input('product_ids');
        $discountPercent = $request->input('discount_percent');

        // دریافت همه محصولات
        $products = Product::whereIn('id', $productIds)->get();

        if ($products->isEmpty()) {
            return response(['error' => 'محصولی یافت نشد'], 404);
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
    public function deleteImage(Product $product, $imageId)
    {
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
