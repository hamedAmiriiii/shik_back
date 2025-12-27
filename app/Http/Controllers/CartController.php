<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\State;
use App\Models\City;
use App\Models\Purchase;
use App\Models\PurchasedProduct;
use App\Models\UserShiksho;
use App\Tools\SmsTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    /**
     * نمایش سبد خرید فعلی مشتری
     */
    public function show(Request $request)
    {
        $customer = $request->user();
        
        $cart = Cart::where('customer_id', $customer->id)
            ->where('status', 'pending')
            ->with(['items.product.images', 'items.product.categories'])
            ->first();

        if (!$cart) {
            return response([
                'cart' => null,
                'items' => [],
                'total' => 0,
                'items_count' => 0
            ]);
        }

        return response([
            'cart' => $cart,
            'items' => $cart->items,
            'total' => $cart->total,
            'items_count' => $cart->items_count
        ]);
    }

    /**
     * ایجاد یا به‌روزرسانی سبد خرید
     * products: [{product_id: 1, quantity: 2}, ...]
     */
    public function store(Request $request)
    {
        $request->validate([
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
        ]);

        $customer = $request->user();

        // بررسی موجودی محصولات (بدون کسر موجودی)
        $productIds = array_column($request->input('products'), 'product_id');
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

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

        DB::beginTransaction();
        try {
            // پیدا کردن یا ایجاد سبد خرید pending
            $cart = Cart::firstOrCreate(
                [
                    'customer_id' => $customer->id,
                    'status' => 'pending'
                ],
                [
                    'status' => 'pending'
                ]
            );

            // حذف آیتم‌های قبلی سبد
            $cart->items()->delete();

            // اضافه کردن آیتم‌های جدید
            foreach ($request->input('products') as $productData) {
                $product = $products->get($productData['product_id']);
                
                CartItem::create([
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                    'quantity' => $productData['quantity'],
                    'price' => $product->sale_price, // قیمت فعلی محصول
                ]);
            }

            DB::commit();

            // بارگذاری روابط
            $cart->load(['items.product.images', 'items.product.categories']);

            return response([
                'message' => 'سبد خرید با موفقیت به‌روزرسانی شد',
                'cart' => $cart,
                'items' => $cart->items,
                'total' => $cart->total,
                'items_count' => $cart->items_count
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response([
                'error' => 'خطا در به‌روزرسانی سبد خرید',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * تکمیل اطلاعات ارسال سبد خرید
     */
    public function updateShippingInfo(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'required|string|digits:11',
            'address' => 'required|string',
            'state_id' => 'required|exists:states,id',
            'city_id' => 'required|exists:cities,id',
            'postal_code' => 'required|string|max:10',
        ]);

        $customer = $request->user();

        $cart = Cart::where('customer_id', $customer->id)
            ->where('status', 'pending')
            ->first();

        if (!$cart) {
            return response([
                'error' => 'سبد خرید یافت نشد'
            ], 404);
        }

        // دریافت نام استان و شهر
        $state = State::find($request->input('state_id'));
        $city = City::find($request->input('city_id'));

        if (!$state || !$city) {
            return response([
                'error' => 'استان یا شهر یافت نشد'
            ], 404);
        }

        // به‌روزرسانی اطلاعات ارسال در سبد خرید
        $cart->update([
            'shipping_name' => $request->input('name'),
            'shipping_last_name' => $request->input('last_name'),
            'shipping_phone' => $request->input('phone'),
            'shipping_address' => $request->input('address'),
            'shipping_state_id' => $request->input('state_id'),
            'shipping_state_name' => $state->name,
            'shipping_city_id' => $request->input('city_id'),
            'shipping_city_name' => $city->name,
            'shipping_postal_code' => $request->input('postal_code'),
        ]);

        // ذخیره اطلاعات به عنوان آدرس پیش‌فرض در پروفایل مشتری (phone در cart ذخیره می‌شود، نه در customer)
        $customer->update([
            'name' => $request->input('name'),
            'last_name' => $request->input('last_name'),
            'state_id' => $request->input('state_id'),
            'city_id' => $request->input('city_id'),
            'address' => $request->input('address'),
            'postal_code' => $request->input('postal_code'),
        ]);

        // بارگذاری روابط
        $cart->load(['items.product.images', 'items.product.categories']);

        return response([
            'message' => 'اطلاعات ارسال با موفقیت ذخیره شد',
            'cart' => $cart,
            'items' => $cart->items,
            'total' => $cart->total,
            'items_count' => $cart->items_count
        ]);
    }

    /**
     * دریافت آدرس پیش‌فرض مشتری
     */
    public function getDefaultAddress(Request $request)
    {
        $customer = $request->user();
        $customer->load(['state', 'city']);

        return response([
            'name' => $customer->name,
            'last_name' => $customer->last_name,
            'phone' => $customer->phone,
            'address' => $customer->address,
            'state_id' => $customer->state_id,
            'state_name' => $customer->state ? $customer->state->name : null,
            'city_id' => $customer->city_id,
            'city_name' => $customer->city ? $customer->city->name : null,
            'postal_code' => $customer->postal_code,
        ]);
    }

    /**
     * تکمیل سفارش بعد از پرداخت بانکی
     */
    public function completeOrder(Request $request)
    {
        $request->validate([
            'use_credit' => 'nullable|boolean', // آیا کاربر می‌خواهد از اعتبارش استفاده کند؟
        ]);

        $customer = $request->user();
        $useCredit = $request->input('use_credit', false);

        $cart = Cart::where('customer_id', $customer->id)
            ->where('status', 'pending')
            ->with(['items.product'])
            ->first();

        if (!$cart) {
            return response([
                'error' => 'سبد خرید یافت نشد'
            ], 404);
        }

        // بررسی اینکه اطلاعات ارسال کامل است
        if (!$cart->shipping_name || !$cart->shipping_address || !$cart->shipping_phone) {
            return response([
                'error' => 'اطلاعات ارسال کامل نیست. لطفاً ابتدا اطلاعات ارسال را تکمیل کنید.'
            ], 400);
        }

        // بررسی اینکه سبد خالی نیست
        if ($cart->items->count() === 0) {
            return response([
                'error' => 'سبد خرید خالی است'
            ], 400);
        }

        // بررسی موجودی محصولات (دوباره بررسی می‌کنیم)
        foreach ($cart->items as $item) {
            $product = $item->product;
            if (!$product) {
                return response(['error' => 'محصول یافت نشد'], 404);
            }

            if ($product->quantity < $item->quantity) {
                return response([
                    'error' => "موجودی محصول '{$product->name}' کافی نیست. موجودی: {$product->quantity}، درخواستی: {$item->quantity}"
                ], 400);
            }
        }

        DB::beginTransaction();
        try {
            // محاسبه مجموع مبلغ خرید
            $originalTotalAmount = $cart->total;
            $phone = $cart->shipping_phone;

            $totalAmount = $originalTotalAmount;
            $creditUsed = 0;
            $userShiksho = null;

            // اگر کاربر می‌خواهد از اعتبار استفاده کند
            if ($phone && $useCredit) {
                $userShiksho = UserShiksho::where('phone', $phone)->first();
                if ($userShiksho && $userShiksho->credit > 0) {
                    // استفاده از اعتبار (تا حداکثر مبلغ خرید)
                    $creditUsed = min($userShiksho->credit, $originalTotalAmount);
                    $userShiksho->useCredit($creditUsed);
                    // مبلغ نهایی بعد از کسر اعتبار
                    $totalAmount = $originalTotalAmount - $creditUsed;
                }
            }

            $creditEarned = 0;
            
            // اگر اعتبار فعال است، اعتبار جدید را محاسبه کن
            $enableLoyaltyCredit = \App\Models\Setting::isEnabled('enable_loyalty_credit', true);
            if ($phone && $enableLoyaltyCredit) {
                // محاسبه اعتبار کسب شده (بر اساس مبلغ اصلی خرید، قبل از کسر اعتبار استفاده شده)
                $creditEarned = UserShiksho::calculateCredit($originalTotalAmount);
            }

            // ایجاد Purchase
            $purchase = Purchase::create([
                'phone' => $phone,
                'total_amount' => $totalAmount,
                'credit_used' => $creditUsed,
                'credit_earned' => $creditEarned,
            ]);

            // ذخیره محصولات خریداری شده
            foreach ($cart->items as $item) {
                PurchasedProduct::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'purchase_price' => $item->product->purchase_price,
                    'sale_price' => $item->price, // قیمت ذخیره شده در cart
                ]);

                // کسر موجودی محصول
                $item->product->decrement('quantity', $item->quantity);
            }

            // تغییر status سبد به completed
            $cart->update([
                'status' => 'completed'
            ]);

            // اگر شماره تلفن وجود دارد
            if ($phone) {
                $enableLoyaltyCredit = \App\Models\Setting::isEnabled('enable_loyalty_credit', true);
                
                if ($enableLoyaltyCredit) {
                    // به‌روزرسانی اعتبار
                    UserShiksho::updateCredit($phone, $creditEarned);

                    // ارسال پیامک
                    $creditFormatted = number_format($creditEarned, 0);
                    $text = "شیک شو\nهمراه عزیز مبلغ {$creditFormatted} تومان به اعتبار شما برای خرید بعدی اضافه شد";
                    SmsTools::sendSms($phone, $text);
                } else {
                    // اگر اعتبار غیرفعال باشد، فقط پیام ساده بفرست
                    $text = "شیکشو\nبا تشکر از خرید شما";
                    SmsTools::sendSms($phone, $text);
                }
            }

            DB::commit();

            // بارگذاری روابط
            $purchase->load('purchasedProducts.product');

            return response([
                'message' => 'سفارش با موفقیت ثبت شد',
                'purchase' => $purchase,
                'cart' => $cart
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response([
                'error' => 'خطا در ثبت سفارش',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * حذف سبد خرید
     */
    public function destroy(Request $request)
    {
        $customer = $request->user();

        $cart = Cart::where('customer_id', $customer->id)
            ->where('status', 'pending')
            ->first();

        if (!$cart) {
            return response([
                'error' => 'سبد خرید یافت نشد'
            ], 404);
        }

        $cart->delete();

        return response([
            'message' => 'سبد خرید با موفقیت حذف شد'
        ]);
    }
}

