<?php

namespace App\Http\Controllers;

use App\Models\PurchasedProduct;
use App\Models\Purchase;
use App\Models\Product;
use App\Models\UserShiksho;
use App\Models\CustomerPhone;
use App\Tools\SmsTools;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Morilog\Jalali\Jalalian;


class PurchasedProductController extends Controller
{
    public function index(Request $request)
{
    $query = Purchase::with('purchasedProducts.product')->orderBy('id', 'desc');

    // جستجو بر اساس searchFilterModel
    $searchDataModel = json_decode($request->input('searchFilterModel'));
    if ($searchDataModel) {
        $query->where(function($q) use ($searchDataModel) {
            if (is_object($searchDataModel)) {
                // جستجو بر اساس شماره تلفن
                if (isset($searchDataModel->phone)) {
                    $q->where('phone', 'like', '%' . $searchDataModel->phone . '%');
                }
            } else if (is_string($searchDataModel)) {
                // اگر یک رشته ساده بود، در شماره تلفن جستجو می‌کند
                $q->where('phone', 'like', '%' . $searchDataModel . '%');
            }
        });
    }

    // فیلتر تاریخ
    if ($request->has('filter')) {
        if ($request->filter === 'today') {
            $query->whereDate('created_at', Carbon::today());
        } elseif ($request->filter === 'week') {
            // فیلتر هفته شمسی (شنبه تا جمعه)
            $now = Jalalian::now();
            $dayOfWeek = $now->getDayOfWeek(); // 0 = شنبه, 6 = جمعه
            $startOfWeek = Jalalian::now()->subDays($dayOfWeek)->toCarbon()->startOfDay();
            $endOfWeek = Jalalian::now()->addDays(6 - $dayOfWeek)->toCarbon()->endOfDay();
            $query->whereBetween('created_at', [$startOfWeek, $endOfWeek]);
        } elseif ($request->filter === 'month') {
            $query->whereMonth('created_at', Carbon::now()->month)
                  ->whereYear('created_at', Carbon::now()->year);
        } elseif ($request->filter === 'range') {
            // فیلتر بازه تاریخ شمسی
            if ($request->has('from_date')) {
                $fromDate = json_decode($request->input('from_date'));
                $fromCarbon = (new Jalalian($fromDate->year, $fromDate->month, $fromDate->day))->toCarbon()->startOfDay();
                $query->where('created_at', '>=', $fromCarbon);
            }
            if ($request->has('to_date')) {
                $toDate = json_decode($request->input('to_date'));
                $toCarbon = (new Jalalian($toDate->year, $toDate->month, $toDate->day))->toCarbon()->endOfDay();
                $query->where('created_at', '<=', $toCarbon);
            }
        }
    }

    $items = $query->paginate();

    // محاسبه مجموع قیمت خرید برای آیتم‌های این صفحه
    $total = Purchase::whereIn('id', $items->pluck('id'))
        ->select(DB::raw('SUM(total_amount) as total'))
        ->value('total');

    // اضافه کردن به meta به شکل درست
    $items->withPath(url()->current()); // حفظ مسیر URL

    // اضافه کردن custom meta
    $itemsArray = $items->toArray();
    $itemsArray['total_purchase_price'] = $total;

    return response($itemsArray, 200);
}


    public function store(Request $request)
    {
        $request->validate([
            'phone' => 'nullable|string|digits:11',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
            'products.*.sale_price' => 'nullable|numeric|min:0', // قیمت فروش با تخفیف (اختیاری)
            'products.*.discount_percent' => 'nullable|numeric|min:0|max:100', // درصد تخفیف (اختیاری)
            'use_credit' => 'nullable|boolean', // آیا کاربر می‌خواهد از اعتبارش استفاده کند؟
        ]);

        $phone = $request->input('phone');
        $useCredit = $request->input('use_credit', false);
        
        // خواندن همه محصولات در یک query
        $productIds = array_column($request->input('products'), 'product_id');
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');
        
        // بررسی موجودی محصولات قبل از ثبت خرید
        foreach ($request->input('products') as $productData) {
            $product = $products->get($productData['product_id']);
            if (!$product) {
                return response(['error' => 'محصول یافت نشد'], 404);
            }
            
            $requestedQuantity = $productData['quantity'];
            if ($product->quantity < $requestedQuantity) {
                return response([
                    'error' => "موجودی محصول '{$product->name}' کافی نیست. موجودی: {$product->quantity}، درخواستی: {$requestedQuantity}"
                ], 400);
            }
        }
        
        // محاسبه مجموع مبلغ خرید بر اساس sale_price (با در نظر گیری تخفیف)
        $originalTotalAmount = 0;
        $productsData = [];
        foreach ($request->input('products') as $productData) {
            $product = $products->get($productData['product_id']);
            
            // تعیین قیمت فروش: اگر sale_price ارسال شده از آن استفاده کن، در غیر این صورت از product.sale_price
            // یا اگر discount_percent داده شده، درصد تخفیف را اعمال کن
            $baseSalePrice = $product->sale_price;
            $quantity = $productData['quantity'];
            
            if (isset($productData['sale_price']) && $productData['sale_price'] !== null) {
                // اگر sale_price مستقیم داده شده
                $salePrice = $productData['sale_price'];
            } elseif (isset($productData['discount_percent']) && $productData['discount_percent'] > 0) {
                // اگر discount_percent داده شده
                $discountAmount = ($baseSalePrice * $productData['discount_percent']) / 100;
                $priceAfterDiscount = max(0, $baseSalePrice - $discountAmount);
                
                // رند کردن به عدد فرد که سه رقم آخرش 0 باشد
                $salePrice = $this->roundToOddWithZeroEnding($priceAfterDiscount);
            } else {
                // بدون تخفیف
                $salePrice = $baseSalePrice;
            }
            
            $originalTotalAmount += $quantity * $salePrice;
            
            // ذخیره اطلاعات محصول برای استفاده بعدی
            $productsData[] = [
                'product_id' => $productData['product_id'],
                'quantity' => $quantity,
                'sale_price' => $salePrice, // قیمت واقعی فروش (با تخفیف)
                'purchase_price' => $product->purchase_price, // برای ذخیره در purchased_products
            ];
        }

        $totalAmount = $originalTotalAmount;
        $creditUsed = 0;
        $userShiksho = null;

        // اگر شماره تلفن وجود دارد و کاربر می‌خواهد از اعتبار استفاده کند
        if ($phone && $useCredit) {
            $userShiksho = UserShiksho::where('phone', $phone)->first();
            if ($userShiksho && $userShiksho->credit > 0) {
                // استفاده از اعتبار (تا حداکثر مبلغ خرید)
                $creditUsed = min($userShiksho->credit, $originalTotalAmount);
                $userShiksho->useCredit($creditUsed);
                // مبلغ نهایی بعد از تخفیف
                $totalAmount = $originalTotalAmount - $creditUsed;
            }
        }

        $creditEarned = 0;
        
        // اگر شماره تلفن وجود دارد، اعتبار جدید را محاسبه کن
        if ($phone) {
            // محاسبه اعتبار کسب شده (بر اساس مبلغ اصلی خرید، قبل از کسر اعتبار استفاده شده)
            $creditEarned = UserShiksho::calculateCredit($originalTotalAmount);
        }

        // ایجاد سبد خرید (Purchase)
        $purchase = Purchase::create([
            'phone' => $phone,
            'total_amount' => $totalAmount,
            'credit_used' => $creditUsed,
            'credit_earned' => $creditEarned,
        ]);

        // ذخیره محصولات خریداری شده و لینک کردن به سبد خرید
        $purchasedProducts = [];
        foreach ($productsData as $productData) {
            $purchasedProducts[] = PurchasedProduct::create([
                'purchase_id' => $purchase->id,
                'product_id' => $productData['product_id'],
                'quantity' => $productData['quantity'],
                'purchase_price' => $productData['purchase_price'], // قیمت خرید محصول برای ثبت
                'sale_price' => $productData['sale_price'], // قیمت واقعی فروش (با تخفیف)
            ]);
        }

        // کسر موجودی محصولات بعد از ثبت خرید
        foreach ($productsData as $productData) {
            $product = $products->get($productData['product_id']);
            $product->decrement('quantity', $productData['quantity']);
        }

        // اگر شماره تلفن وجود دارد، اعتبار را به‌روزرسانی کن و پیامک بفرست
        if ($phone) {
            // به‌روزرسانی اعتبار (اعتبار قبلی صفر می‌شود و اعتبار جدید اضافه می‌شود)
            UserShiksho::updateCredit($phone, $creditEarned);

            // ثبت شماره تلفن در جدول customer_phones
            CustomerPhone::createNewPhone($phone);

            // ارسال پیامک بعد از ذخیره خرید
            $creditFormatted = number_format($creditEarned, 0);
            $text = "شیک شو\nهمراه عزیز مبلغ {$creditFormatted} تومان به اعتبار شما برای خرید بعدی اضافه شد";
            SmsTools::sendSms($phone, $text);
        }

        // برگرداندن سبد خرید با محصولاتش
        $purchase->load('purchasedProducts.product');
        
        return response($purchase, 201);
    }

    public function show(Purchase $purchase)
    {
        return response($purchase->load('purchasedProducts.product'), 200);
    }

    public function update(Request $request, Purchase $purchase)
    {
        $request->validate([
            'phone' => 'sometimes|nullable|string|digits:11',
            'total_amount' => 'sometimes|required|numeric|min:0',
            'credit_used' => 'sometimes|required|numeric|min:0',
            'credit_earned' => 'sometimes|required|numeric|min:0',
        ]);

        $purchase->update($request->only(['phone', 'total_amount', 'credit_used', 'credit_earned']));

        return response($purchase->load('purchasedProducts.product'), 200);
    }

    public function destroy(Purchase $purchase)
    {
        $purchase->delete();
        return response(['message' => 'سبد خرید حذف شد'], 200);
    }

    /**
     * رند کردن به عدد فرد که دهگان و صدگانش 0 باشد
     * مثال: 130.5 -> 101, 1300 -> 1301, 1450 -> 1501
     * 
     * @param float $number
     * @return float
     */
    private function roundToOddWithZeroEnding($number)
    {
        if ($number <= 0) {
            return 0;
        }

        // رند کردن به نزدیک‌ترین 100 (دهگان و صدگان 0 می‌شود)
        $roundedToHundred = round($number, -2);
        
        // اعداد ممکن: ..., 101, 103, 105, 107, 109, 201, 203, ..., 1301, 1303, ...
        // یعنی باید یکان فرد باشد (1, 3, 5, 7, 9)
        
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

    /**
     * دریافت اعتبار کاربر بر اساس شماره تلفن
     * 
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function getCreditByPhone(Request $request)
    {
        $request->validate([
            'phone' => 'required|string|digits:11',
        ]);

        $phone = $request->input('phone');
        $userShiksho = UserShiksho::where('phone', $phone)->first();

        $credit = $userShiksho ? $userShiksho->credit : 0;

        return response([
            'phone' => $phone,
            'use_credit' => $credit,
            'credit' => $credit,
        ], 200);
    }
}
