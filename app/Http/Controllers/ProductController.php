<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use http\Env\Response;

class ProductController extends Controller
{
    /**
     * نمایش لیست محصولات
     */
    public function index(Request $request)
    {
        $query = Product::query();
        
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
        
        // دریافت تعداد آیتم در هر صفحه از request (پیش‌فرض 50)
        $perPage = $request->input('per_page', 10);
        
        $products = $query->orderBy('id', 'desc')->paginate($perPage);
        
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
        
        $products = $query->orderBy('id', 'desc')->get();
        
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
        return response($product);
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
        ]);

        // محاسبه قیمت با تخفیف در صورت وجود
        if (isset($fields['discount_percent'])) {
            if ($fields['discount_percent'] > 0) {
                // اگر original_sale_price داده شده از آن استفاده کن، در غیر این صورت از original_sale_price موجود یا sale_price
                $basePrice = $fields['original_sale_price'] ?? $product->original_sale_price ?? $fields['sale_price'];
                
                // محاسبه تخفیف
                $discountAmount = ($basePrice * $fields['discount_percent']) / 100;
                $priceAfterDiscount = max(0, $basePrice - $discountAmount);
                
                // رند کردن به عدد فرد که دهگان و صدگانش 0 باشد
                $fields['sale_price'] = $this->roundToOddWithZeroEnding($priceAfterDiscount);
                $fields['original_sale_price'] = $basePrice;
            } else {
                // اگر discount_percent = 0 است، تخفیف را حذف کن (sale_price = original_sale_price)
                // اولویت با original_sale_price موجود در دیتابیس است
                $basePrice = $fields['original_sale_price'] ?? $product->original_sale_price ?? $fields['sale_price'];
                $fields['sale_price'] = $basePrice;
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
        return response($product);
    }

    /**
     * حذف محصول
     */
    public function destroy(Product $product)
    {
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
     * رند کردن به عدد فرد که دهگان و صدگانش 0 باشد
     * (همان متد در PurchasedProductController)
     */
    private function roundToOddWithZeroEnding($number)
    {
        if ($number <= 0) {
            return 0;
        }

        // رند کردن به نزدیک‌ترین 100 (دهگان و صدگان 0 می‌شود)
        $roundedToHundred = round($number, -2);
        
        // پیدا کردن نزدیک‌ترین عدد فرد که دهگان و صدگانش 0 باشد
        $base = (int)($roundedToHundred);
        $closest = $base;
        $minDiff = abs($number - $base);
        
        // بررسی 5 گزینه: base + 1, base + 3, base + 5, base + 7, base + 9
        for ($i = 1; $i <= 9; $i += 2) {
            $candidate = $base + $i;
            $diff = abs($number - $candidate);
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $closest = $candidate;
            }
        }
        
        // همچنین بررسی base - 9, base - 7, base - 5, base - 3, base - 1
        for ($i = 1; $i <= 9; $i += 2) {
            $candidate = $base - $i;
            if ($candidate > 0) {
                $diff = abs($number - $candidate);
                if ($diff < $minDiff) {
                    $minDiff = $diff;
                    $closest = $candidate;
                }
            }
        }
        
        return (float)$closest;
    }
}
