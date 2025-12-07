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
            'use_credit' => 'nullable|boolean', // آیا کاربر می‌خواهد از اعتبارش استفاده کند؟
        ]);

        $phone = $request->input('phone');
        $useCredit = $request->input('use_credit', false);
        
        // خواندن همه محصولات در یک query
        $productIds = array_column($request->input('products'), 'product_id');
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');
        
        // محاسبه مجموع مبلغ خرید بر اساس sale_price (قبل از تخفیف)
        $originalTotalAmount = 0;
        $productsData = [];
        foreach ($request->input('products') as $productData) {
            $product = $products->get($productData['product_id']);
            if (!$product) {
                return response(['error' => 'محصول یافت نشد'], 404);
            }
            
            $salePrice = $product->sale_price;
            $quantity = $productData['quantity'];
            $originalTotalAmount += $quantity * $salePrice;
            
            // ذخیره اطلاعات محصول برای استفاده بعدی
            $productsData[] = [
                'product_id' => $productData['product_id'],
                'quantity' => $quantity,
                'sale_price' => $salePrice,
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
            ]);
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
