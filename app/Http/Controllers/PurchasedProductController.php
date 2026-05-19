<?php

namespace App\Http\Controllers;

use App\Models\PurchasedProduct;
use App\Models\Purchase;
use App\Models\Product;
use App\Models\Installment;
use App\Models\UserShiksho;
use App\Models\CustomerPhone;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Tools\SmsTools;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Morilog\Jalali\Jalalian;


class PurchasedProductController extends Controller
{
    public function index(Request $request)
{
    $atelierId = $this->shopAtelierIdOrAbort($request);
    \App\Models\Setting::setShopContext($atelierId);

    // فقط Purchase هایی که:
    // 1. cart_id ندارند (فروش فیزیکی مستقیم)
    // 2. یا cart_id دارند و Cart status آن‌ها shipped است
    // 3. مجموع مبلغشان بیشتر از 0 است
    $query = Purchase::with(['purchasedProducts.product', 'installments'])
        ->where('atelier_id', $atelierId)
        ->where('total_amount', '>', 0) // فقط خریدهایی که مجموع مبلغشان بیشتر از 0 است
        ->where(function($q) {
            $q->whereNull('cart_id') // فروش فیزیکی
              ->orWhereHas('cart', function($cartQuery) {
                  $cartQuery->where('status', Cart::STATUS_SHIPPED); // سفارش اینترنتی که shipped شده
              });
        })
        ->orderBy('id', 'desc');

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

    // محاسبه مجموع مبلغ واقعی پرداخت شده برای آیتم‌های این صفحه
    $total = 0;
    foreach ($items->items() as $purchase) {
        // برای خریدهای اقساطی: مجموع قسط‌های پرداخت شده
        // برای خریدهای نقدی: مبلغ کل خرید
        if ($purchase->isInstallment()) {
            $total += $purchase->paid_amount;
        } else {
            $total += $purchase->total_amount;
        }
    }

    // اضافه کردن به meta به شکل درست
    $items->withPath(url()->current()); // حفظ مسیر URL

    // تبدیل به array و اضافه کردن فیلد paid_amount برای خریدهای اقساطی
    $itemsArray = $items->toArray();
    foreach ($itemsArray['data'] as &$purchaseData) {
        $purchase = $items->firstWhere('id', $purchaseData['id']);
        if ($purchase && $purchase->isInstallment()) {
            // اضافه کردن فیلد paid_amount برای خریدهای اقساطی
            $purchaseData['paid_amount'] = (float) $purchase->paid_amount;
        }
    }
    unset($purchaseData);

    $itemsArray['total_purchase_price'] = $total;

    return response($itemsArray, 200);
}


    public function store(Request $request)
    {
        $this->bindShopSettingAtelierFromRequest($request);

        $request->validate([
            'phone' => 'nullable|string|digits:11',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
            'products.*.sale_price' => 'nullable|numeric|min:0', // قیمت فروش با تخفیف (اختیاری)
            'products.*.discount_percent' => 'nullable|numeric|min:0|max:100', // درصد تخفیف (اختیاری)
            'products.*.size' => 'nullable|string|max:255', // سایز انتخاب شده (اختیاری)
            'products.*.color' => 'nullable|string|max:255', // رنگ انتخاب شده (اختیاری)
            'use_credit' => 'nullable|boolean', // آیا کاربر می‌خواهد از اعتبارش استفاده کند؟
            'discount_amount' => 'nullable|numeric|min:0', // مبلغ تخفیف مستقیم (اختیاری)
            'payment_type' => 'nullable|string|in:cash,installment', // نوع پرداخت: نقدی یا اقساطی
            'installment_count' => 'required_if:payment_type,installment|integer|min:2|max:24', // تعداد اقساط (حداقل 2، حداکثر 24)
        ]);

        $phone = $request->input('phone');
        $useCredit = $request->input('use_credit', false);
        $paymentType = $request->input('payment_type', 'cash'); // پیش‌فرض: نقدی
        $installmentCount = $request->input('installment_count');
        
        // خواندن همه محصولات در یک query
        $productIds = array_column($request->input('products'), 'product_id');
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

        $staffAtelierId = $this->staffShopAtelierId($request);
        $distinctAtelierIds = $products->pluck('atelier_id')->unique()->filter(function ($id) {
            return $id !== null && $id !== '';
        });
        if ($staffAtelierId !== null) {
            foreach ($products as $p) {
                if ((int) $p->atelier_id !== (int) $staffAtelierId) {
                    return response(['error' => 'یک یا چند محصول متعلق به فروشگاه شما نیست'], 422);
                }
            }
            $purchaseAtelierId = (int) $staffAtelierId;
        } else {
            if ($distinctAtelierIds->count() > 1) {
                return response(['error' => 'همه محصولات باید متعلق به یک فروشگاه باشند'], 422);
            }
            $purchaseAtelierId = $distinctAtelierIds->isEmpty() ? null : (int) $distinctAtelierIds->first();
        }

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
                'size' => $productData['size'] ?? null, // سایز انتخاب شده
                'color' => $productData['color'] ?? null, // رنگ انتخاب شده
            ];
        }

        // دریافت مبلغ تخفیف مستقیم (اگر وجود داشته باشد)
        $discountAmount = $request->input('discount_amount', 0);
        if ($discountAmount < 0) {
            $discountAmount = 0;
        }
        
        // کسر مبلغ تخفیف از قیمت کل
        $totalAmount = max(0, $originalTotalAmount - $discountAmount);
        $creditUsed = 0;
        $userShiksho = null;

        // اگر شماره تلفن وجود دارد و کاربر می‌خواهد از اعتبار استفاده کند
        if ($phone && $useCredit) {
            $userShikshoQuery = UserShiksho::where('phone', $phone);
            if ($purchaseAtelierId !== null) {
                $userShikshoQuery->where('atelier_id', $purchaseAtelierId);
            }
            $userShiksho = $userShikshoQuery->first();
            if ($userShiksho && $userShiksho->credit > 0) {
                // استفاده از اعتبار (تا حداکثر مبلغ خرید بعد از تخفیف)
                $creditUsed = min($userShiksho->credit, $totalAmount);
                $userShiksho->useCredit($creditUsed);
                // مبلغ نهایی بعد از کسر اعتبار
                $totalAmount = $totalAmount - $creditUsed;
            }
        }

        $creditEarned = 0;
        
        // اگر تخفیف مستقیم داده نشده باشد و شماره تلفن وجود دارد و اعتبار فعال است، اعتبار جدید را محاسبه کن
        // اگر discount_amount > 0 باشد، اعتبار اضافه نمی‌شود
        $enableLoyaltyCredit = \App\Models\Setting::isEnabled('enable_loyalty_credit', true);
        if ($phone && $enableLoyaltyCredit && $discountAmount == 0) {
            // محاسبه اعتبار کسب شده (بر اساس مبلغ اصلی خرید، قبل از کسر اعتبار استفاده شده)
            $creditEarned = UserShiksho::calculateCredit($originalTotalAmount);
        }

        // محاسبه مبلغ هر قسط در صورت اقساطی بودن (با در نظر گیری سود ماهانه)
        $installmentAmount = null;
        $finalTotalAmount = $totalAmount; // مبلغ نهایی (با سود در صورت اقساطی)
        
        if ($paymentType === 'installment' && $installmentCount) {
            // خواندن نرخ سود ماهانه از ستینگ (پیش‌فرض: 0 یعنی بدون سود)
            $monthlyInterestRate = (float) \App\Models\Setting::get('installment_monthly_interest_rate', 0);
            
            // یک سوم مبلغ به صورت نقد (قسط اول)
            $firstInstallmentAmount = $this->roundToThreeZeroEnding($totalAmount / 3);
            
            // بقیه مبلغ (دو سوم)
            $remainingAmount = $totalAmount - $firstInstallmentAmount;
            
            // تعداد ماه‌های باقیمانده (بعد از قسط اول)
            $remainingMonths = $installmentCount - 1;
            
            if ($monthlyInterestRate > 0 && $remainingMonths > 0) {
                // محاسبه سود: مبلغ باقیمانده × نرخ سود × تعداد ماه‌های باقیمانده
                $totalInterest = $remainingAmount * ($monthlyInterestRate / 100) * $remainingMonths;
                
                // مبلغ کل با سود = مبلغ باقیمانده + سود
                $remainingAmountWithInterest = $remainingAmount + $totalInterest;
                
                // مبلغ کل نهایی = قسط اول + بقیه با سود
                $finalTotalAmount = $this->roundToThreeZeroEnding($firstInstallmentAmount + $remainingAmountWithInterest);
                
                // مبلغ هر قسط باقیمانده
                $installmentAmount = $this->roundToThreeZeroEnding($remainingAmountWithInterest / $remainingMonths);
            } else {
                // بدون سود
                $finalTotalAmount = $totalAmount;
                // مبلغ هر قسط باقیمانده
                $installmentAmount = $this->roundToThreeZeroEnding($remainingAmount / $remainingMonths);
            }
        }

        // ایجاد سبد خرید (Purchase)
        $purchase = Purchase::create([
            'phone' => $phone,
            'total_amount' => $finalTotalAmount, // مبلغ کل با سود (در صورت اقساطی)
            'credit_used' => $creditUsed,
            'credit_earned' => $creditEarned,
            'payment_type' => $paymentType,
            'installment_count' => $paymentType === 'installment' ? $installmentCount : null,
            'installment_amount' => $installmentAmount,
            'atelier_id' => $purchaseAtelierId,
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
                'size' => $productData['size'] ?? null, // سایز انتخاب شده
                'color' => $productData['color'] ?? null, // رنگ انتخاب شده
            ]);
        }

        // کسر موجودی محصولات بعد از ثبت خرید
        foreach ($productsData as $productData) {
            $product = $products->get($productData['product_id']);
            $product->decrement('quantity', $productData['quantity']);
        }

        // ایجاد قسط‌ها در صورت اقساطی بودن
        if ($paymentType === 'installment' && $installmentCount && $installmentAmount) {
            $this->createInstallments($purchase, $installmentCount, $installmentAmount, $finalTotalAmount);
            
            // کسر اعتبار اقساطی: مبلغ باقیمانده (کل مبلغ منهای قسط اول که پرداخت شده - یک سوم)
            if ($phone) {
                $userShiksho = UserShiksho::where('phone', $phone)
                    ->when($purchaseAtelierId !== null, function ($q) use ($purchaseAtelierId) {
                        $q->where('atelier_id', $purchaseAtelierId);
                    })
                    ->first();
                if ($userShiksho) {
                    // مبلغ قسط اول که پرداخت شده است (یک سوم)
                    $firstInstallmentAmount = $this->roundToThreeZeroEnding($finalTotalAmount / 3);
                    // مبلغ باقیمانده که باید از اعتبار اقساطی کسر شود
                    $remainingAmount = $finalTotalAmount - $firstInstallmentAmount;
                    
                    if ($remainingAmount > 0 && $userShiksho->installment_credit >= $remainingAmount) {
                        $userShiksho->useInstallmentCredit($remainingAmount);
                    }
                }
            }
        }

        // اگر شماره تلفن وجود دارد
        if ($phone) {
            $enableLoyaltyCredit = \App\Models\Setting::isEnabled('enable_loyalty_credit', true);
            
            if ($enableLoyaltyCredit && $creditEarned > 0) {
                // به‌روزرسانی اعتبار (اعتبار قبلی صفر می‌شود و اعتبار جدید اضافه می‌شود)
                UserShiksho::updateCredit($phone, $creditEarned, $purchaseAtelierId);

                // ارسال پیامک بعد از ذخیره خرید (فقط اگر اعتبار کسب شده باشد)
                $creditFormatted = number_format($creditEarned, 0);
                $shopName = SmsTools::shopSmsBrand($purchaseAtelierId);
                $text = "{$shopName}\nهمراه عزیز مبلغ {$creditFormatted} تومان به اعتبار شما برای خرید بعدی اضافه شد";
                SmsTools::sendShopSms($phone, $text, (string) $purchase->id, $creditEarned, 'credit', $purchaseAtelierId);
            } else {
                // اگر اعتبار غیرفعال باشد یا اعتبار کسب نشده باشد (به دلیل تخفیف)، فقط پیام ساده بفرست
                $shopName = SmsTools::shopSmsBrand($purchaseAtelierId);
                $text = "{$shopName}\nبا تشکر از خرید شما";
                SmsTools::sendShopSms($phone, $text, (string) $purchase->id, null, 'purchase', $purchaseAtelierId);
            }

            // ثبت شماره تلفن در جدول customer_phones
            CustomerPhone::createNewPhone($phone);
        }

        // برگرداندن سبد خرید با محصولاتش و قسط‌ها (در صورت وجود)
        $purchase->load('purchasedProducts.product');
        if ($paymentType === 'installment') {
            $purchase->load('installments');
        }
        
        return response($purchase, 201);
    }

    public function show(Purchase $purchase)
    {
        $purchase->load('purchasedProducts.product');
        if ($purchase->isInstallment()) {
            $purchase->load('installments');
        }
        return response($purchase, 200);
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

    /**
     * رند کردن به عددی که سه رقم آخرش 0 باشد (یکان، دهگان، صدگان)
     * مثال: 1234567 -> 1234000, 123456 -> 123000, 123789 -> 124000
     * 
     * @param float $number
     * @return float
     */
    private function roundToThreeZeroEnding($number)
    {
        if ($number <= 0) {
            return 0;
        }

        // رند کردن به نزدیک‌ترین عدد که سه رقم آخرش 0 باشد
        return round($number / 1000) * 1000;
    }
     





    /**
     * ایجاد قسط‌ها برای خرید اقساطی
     * قسط اول: یک سوم مبلغ کل (نقد)
     * بقیه اقساط: تقسیم مبلغ باقیمانده با سود
     * 
     * @param Purchase $purchase
     * @param int $installmentCount تعداد کل اقساط
     * @param float $installmentAmount مبلغ هر قسط باقیمانده (بعد از قسط اول)
     * @param float $totalAmount مبلغ کل با سود
     */
    private function createInstallments(Purchase $purchase, int $installmentCount, float $installmentAmount, float $totalAmount)
    {
        $installments = [];
        $today = Jalalian::now();
        $baseDate = $today->toCarbon();
        
        // یک سوم مبلغ کل (قسط اول - نقد)
        $firstInstallmentAmount = $this->roundToThreeZeroEnding($totalAmount / 3);
        
        // تعداد ماه‌های باقیمانده
        $remainingMonths = $installmentCount - 1;
        
        // مبلغ کل باقیمانده با سود
        $remainingAmountWithInterest = $installmentAmount * $remainingMonths;
        
        // محاسبه تفاوت برای اضافه کردن به آخرین قسط
        $expectedTotal = $firstInstallmentAmount + $remainingAmountWithInterest;
        $difference = $totalAmount - $expectedTotal;
        
        for ($i = 1; $i <= $installmentCount; $i++) {
            if ($i === 1) {
                // قسط اول: یک سوم نقد
                $dueDate = $baseDate->toDateString();
                $isPaid = true;
                $paidAt = now();
                $amount = $firstInstallmentAmount;
            } else {
                // بقیه اقساط
                $dueDate = $baseDate->copy()->addMonths($i - 1)->toDateString();
                $isPaid = false;
                $paidAt = null;
                
                $amount = $installmentAmount;
                // اگر آخرین قسط است و تفاوتی وجود دارد، اضافه کن
                if ($i === $installmentCount && abs($difference) > 0.01) {
                    $amount += $difference;
                }
            }
            
            // رند کردن مبلغ قسط به عددی که سه رقم آخرش 0 باشد
            $amount = $this->roundToThreeZeroEnding($amount);
            
            $installments[] = [
                'purchase_id' => $purchase->id,
                'installment_number' => $i,
                'amount' => $amount,
                'due_date' => $dueDate,
                'is_paid' => $isPaid,
                'paid_at' => $paidAt,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        
        Installment::insert($installments);
    }

    /**
     * برگشت یک محصول از یک خرید
     * محصول از لیست خرید حذف شده و موجودی برگردانده می‌شود
     * 
     * @param Purchase $purchase
     * @param PurchasedProduct $purchasedProduct
     * @return \Illuminate\Http\Response
     */
    public function returnItem(Purchase $purchase, PurchasedProduct $purchasedProduct)
    {
        // بررسی اینکه محصول متعلق به این خرید باشد
        if ($purchasedProduct->purchase_id !== $purchase->id) {
            return response(['error' => 'این محصول متعلق به این خرید نیست'], 400);
        }

        // افزایش موجودی محصول
        $product = $purchasedProduct->product;
        $product->increment('quantity', $purchasedProduct->quantity);

        // کم کردن مبلغ از total_amount خرید
        $returnAmount = $purchasedProduct->sale_price * $purchasedProduct->quantity;
        $purchase->total_amount = max(0, $purchase->total_amount - $returnAmount);

        // برگشت اعتبار کسب شده متناسب با مبلغ برگشتی
        $creditReturned = 0;
        if ($purchase->credit_earned > 0 && $purchase->phone) {
            // محاسبه اعتبار برگشتی بر اساس نسبت مبلغ برگشتی
            $creditReturned = UserShiksho::calculateCredit($returnAmount);
            
            // کم کردن از credit_earned خرید
            $purchase->credit_earned = max(0, $purchase->credit_earned - $creditReturned);
            
            // کم کردن از اعتبار کاربر
            $userShikshoQuery = UserShiksho::where('phone', $purchase->phone);
            if ($purchase->atelier_id !== null) {
                $userShikshoQuery->where('atelier_id', $purchase->atelier_id);
            }
            $userShiksho = $userShikshoQuery->first();
            if ($userShiksho && $userShiksho->credit >= $creditReturned) {
                $userShiksho->credit = max(0, $userShiksho->credit - $creditReturned);
                $userShiksho->save();
            }
        }

        $purchase->save();

        // ذخیره اطلاعات برای پاسخ قبل از حذف
        $returnedInfo = [
            'product_id' => $purchasedProduct->product_id,
            'product_name' => $product->name,
            'quantity' => $purchasedProduct->quantity,
            'sale_price' => $purchasedProduct->sale_price,
            'return_amount' => $returnAmount,
            'credit_returned' => $creditReturned,
        ];

        // حذف محصول از لیست خرید
        $purchasedProduct->delete();

        // بارگذاری مجدد خرید با محصولات باقیمانده
        $purchase->load('purchasedProducts.product');

        return response([
            'message' => 'محصول با موفقیت برگشت داده شد',
            'returned_item' => $returnedInfo,
            'purchase' => $purchase,
        ], 200);
    }

    /**
     * دریافت اعتبار کاربر بر اساس شماره تلفن
     * 
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function getCreditByPhone(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        \App\Models\Setting::setShopContext($atelierId);

        $request->validate([
            'phone' => 'required|string|digits:11',
        ]);

        $phone = $request->input('phone');
        $userShiksho = UserShiksho::where('phone', $phone)
            ->where('atelier_id', $atelierId)
            ->first();

        $credit = $userShiksho ? $userShiksho->credit : 0;

        return response([
            'phone' => $phone,
            'use_credit' => $credit,
            'credit' => $credit,
        ], 200);
    }

    /**
     * دریافت اعتبار و سقف خرید اقساطی کاربر
     * 
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function getInstallmentCredit(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        \App\Models\Setting::setShopContext($atelierId);

        $request->validate([
            'phone' => 'required|string|digits:11',
        ]);

        $phone = $request->input('phone');
        $userShiksho = UserShiksho::where('phone', $phone)
            ->where('atelier_id', $atelierId)
            ->first();

        $installmentCredit = $userShiksho ? (float) $userShiksho->installment_credit : 0;
        
        // سقف خرید اقساطی همان اعتبار اقساطی کاربر است
        $installmentLimit = $installmentCredit;

        return response([
            'phone' => $phone,
            'installment_credit' => $installmentCredit,
            'installment_limit' => $installmentLimit,
            'can_buy_installment' => $installmentCredit > 0,
        ], 200);
    }

    /**
     * محاسبه مبلغ اقساط بر اساس مبلغ خرید و تعداد ماه‌ها
     * 
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function calculateInstallments(Request $request)
    {
        $atelierId = $this->resolveAtelierIdForCalculateInstallments($request);
        \App\Models\Setting::setShopContext($atelierId);

        $validated = $request->validate([
            'total_amount' => 'required|numeric|min:0',
            'installment_count' => 'required|integer|min:2|max:24',
            'phone' => 'nullable|string|digits:11',
        ]);

        $totalAmount = (float) $validated['total_amount'];
        $installmentCount = (int) $validated['installment_count'];
        $phone = $validated['phone'] ?? null;

        $monthlyInterestRate = (float) \App\Models\Setting::get('installment_monthly_interest_rate', 0);
        $installmentAmount = 0.0;
        
        // یک سوم مبلغ به صورت نقد (قسط اول)
        $firstInstallmentAmount = $this->roundToThreeZeroEnding($totalAmount / 3);
        
        // بقیه مبلغ (دو سوم)
        $remainingAmount = $totalAmount - $firstInstallmentAmount;
        
        // تعداد ماه‌های باقیمانده (بعد از قسط اول)
        $remainingMonths = $installmentCount - 1;
        
        $finalTotalAmount = $totalAmount;
        $totalInterest = 0;
        $installmentDetails = [];

        if ($monthlyInterestRate > 0 && $remainingMonths > 0) {
            // محاسبه سود: مبلغ باقیمانده × نرخ سود × تعداد ماه‌های باقیمانده
            $totalInterest = $remainingAmount * ($monthlyInterestRate / 100) * $remainingMonths;
            
            // مبلغ کل با سود = مبلغ باقیمانده + سود
            $remainingAmountWithInterest = $remainingAmount + $totalInterest;
            
            // مبلغ کل نهایی = قسط اول + بقیه با سود
            $finalTotalAmount = $this->roundToThreeZeroEnding($firstInstallmentAmount + $remainingAmountWithInterest);
            
            // مبلغ هر قسط باقیمانده
            $installmentAmount = $this->roundToThreeZeroEnding($remainingAmountWithInterest / $remainingMonths);
            
            // جزئیات قسط اول
            $installmentDetails[] = [
                'month' => 1,
                'remaining_amount' => $totalAmount,
                'interest' => 0,
                'base_payment' => $firstInstallmentAmount,
                'payment_type' => 'cash',
            ];
            
            // جزئیات بقیه اقساط
            for ($month = 2; $month <= $installmentCount; $month++) {
                $installmentDetails[] = [
                    'month' => $month,
                    'remaining_amount' => $this->roundToThreeZeroEnding($remainingAmountWithInterest - ($installmentAmount * ($month - 2))),
                    'interest' => $this->roundToThreeZeroEnding($totalInterest / $remainingMonths),
                    'base_payment' => $installmentAmount,
                    'payment_type' => 'installment',
                ];
            }
        } else {
            // بدون سود
            $finalTotalAmount = $this->roundToThreeZeroEnding($totalAmount);
            
            // مبلغ هر قسط باقیمانده
            $installmentAmount = $this->roundToThreeZeroEnding($remainingAmount / $remainingMonths);
            
            // جزئیات قسط اول
            $installmentDetails[] = [
                'month' => 1,
                'remaining_amount' => $totalAmount,
                'interest' => 0,
                'base_payment' => $firstInstallmentAmount,
                'payment_type' => 'cash',
            ];
            
            // جزئیات بقیه اقساط
            for ($month = 2; $month <= $installmentCount; $month++) {
                $installmentDetails[] = [
                    'month' => $month,
                    'remaining_amount' => $this->roundToThreeZeroEnding($remainingAmount - ($installmentAmount * ($month - 2))),
                    'interest' => 0,
                    'base_payment' => $installmentAmount,
                    'payment_type' => 'installment',
                ];
            }
        }

        // چک اعتبار اقساطی در صورت وجود شماره تلفن
        $userInstallmentCredit = null;
        $hasEnoughCredit = null;
        $creditShortage = null;
        
        if ($phone) {
            $userShiksho = UserShiksho::where('phone', $phone)
                ->where('atelier_id', $atelierId)
                ->first();
            $userInstallmentCredit = $userShiksho ? (float) $userShiksho->installment_credit : 0;
            
            // اعتبار اقساطی باید به اندازه کل مبلغ با سود باشد
            $hasEnoughCredit = $userInstallmentCredit >= $finalTotalAmount;
            
            if (!$hasEnoughCredit) {
                $creditShortage = $this->roundToThreeZeroEnding($finalTotalAmount - $userInstallmentCredit);
            }
        }

        $response = [
            'atelier_id' => $atelierId,
            'total_amount' => $this->roundToThreeZeroEnding($totalAmount),
            'installment_count' => $installmentCount,
            'monthly_interest_rate' => $monthlyInterestRate,
            'total_interest' => $this->roundToThreeZeroEnding($totalInterest),
            'final_total_amount' => $finalTotalAmount,
            'installment_amount' => $this->roundToThreeZeroEnding($installmentAmount),
            'installment_details' => $installmentDetails,
        ];

        // اضافه کردن اطلاعات اعتبار اقساطی در صورت وجود شماره تلفن
        if ($phone !== null) {
            $response['phone'] = $phone;
            $response['user_installment_credit'] = $this->roundToThreeZeroEnding($userInstallmentCredit);
            $response['has_enough_credit'] = $hasEnoughCredit;
            
            if (!$hasEnoughCredit) {
                $response['credit_shortage'] = $creditShortage;
                $response['error'] = 'اعتبار اقساطی کاربر کافی نیست. اعتبار مورد نیاز: ' . number_format($finalTotalAmount, 0) . ' تومان، اعتبار موجود: ' . number_format($userInstallmentCredit, 0) . ' تومان';
            }
        }

        if ($phone && $hasEnoughCredit === false) {
            return response()->json($response, 400);
        }

        return response()->json($response, 200);
    }

    /**
     * فروشگاه برای محاسبه اقساط: از کاربر لاگین، یا atelier_id در body (اگر توکن در هدر نرسد).
     */
    private function resolveAtelierIdForCalculateInstallments(Request $request): int
    {
        $requestedAtelierId = $this->parseRequestedAtelierId($request);
        $actor = $this->shopRequestActor($request);

        if ($actor instanceof \App\Models\User) {
            $freshAtelierId = \App\Models\User::where('id', $actor->id)->value('atelier_id');
            if ($freshAtelierId) {
                return (int) $freshAtelierId;
            }
            if ($requestedAtelierId !== null && \App\Models\Atelier::where('id', $requestedAtelierId)->exists()) {
                return $requestedAtelierId;
            }
        }

        if ($requestedAtelierId !== null && \App\Models\Atelier::where('id', $requestedAtelierId)->exists()) {
            return $requestedAtelierId;
        }

        return $this->shopAtelierIdOrAbort($request);
    }
}
