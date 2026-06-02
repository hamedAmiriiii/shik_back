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
use App\Models\CustomerPhone;
use App\Models\CustomerAddress;
use App\Tools\SmsTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CartController extends Controller
{
    /**
     * شناسهٔ فروشگاه برای مشتری لاگین‌شده (سبد و سفارش فقط در همان فروشگاه).
     */
    private function customerShopAtelierId($customer): int
    {
        if (! $customer->atelier_id) {
            abort(response()->json([
                'message' => 'حساب شما به فروشگاه متصل نیست. لطفاً با ارسال کد فروشگاه (atelier_code در query/body یا هدر X-Atelier-Code) دوباره ثبت‌نام یا ورود انجام دهید.',
            ], 422));
        }

        return (int) $customer->atelier_id;
    }

    /**
     * تنظیم زمینهٔ تنظیمات فروشگاه برای خواندن Setting:: در مسیر مشتری.
     */
    private function bindSettingContextForCustomer($customer): void
    {
        \App\Models\Setting::setShopContext($this->customerShopAtelierId($customer));
    }

    /**
     * نمایش سبد خرید فعلی مشتری
     */
    public function show(Request $request)
    {
        $customer = $request->user();
        $atelierId = $this->customerShopAtelierId($customer);
        
        $cart = Cart::where('customer_id', $customer->id)
            ->where('atelier_id', $atelierId)
            ->where('status', Cart::STATUS_PENDING)
            ->with(['items.product.images', 'items.product.categories', 'address'])
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
        $customer = $request->user();
        $atelierId = $this->customerShopAtelierId($customer);

        $request->validate([
            'products' => 'required|array|min:1',
            'products.*.product_id' => [
                'required',
                Rule::exists('products', 'id')->whereNull('deleted_at'),
            ],
            'products.*.quantity' => 'required|integer|min:1',
            'products.*.size' => 'nullable|string|max:255', // سایز انتخاب شده (اختیاری)
            'products.*.color' => 'nullable|string|max:255', // رنگ انتخاب شده (اختیاری)
        ]);

        // بررسی موجودی محصولات (بدون کسر موجودی)
        $productIds = array_column($request->input('products'), 'product_id');
        $products = Product::whereIn('id', $productIds)->where('atelier_id', $atelierId)->get()->keyBy('id');

        if ($products->count() !== count(array_unique($productIds))) {
            return response(['error' => 'یک یا چند محصول در این فروشگاه موجود نیست'], 422);
        }

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
                    'atelier_id' => $atelierId,
                    'status' => Cart::STATUS_PENDING,
                ],
                [
                    'status' => Cart::STATUS_PENDING,
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
                    'size' => $productData['size'] ?? null, // سایز انتخاب شده
                    'color' => $productData['color'] ?? null, // رنگ انتخاب شده
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
     * انتخاب آدرس برای سبد خرید (بر اساس آدرس ID)
     */
    public function setAddress(Request $request)
    {
        $request->validate([
            'address_id' => 'required|exists:customer_addresses,id',
        ]);

        $customer = $request->user();
        $atelierId = $this->customerShopAtelierId($customer);
        $addressId = $request->input('address_id');

        // بررسی اینکه این آدرس متعلق به مشتری است
        $address = CustomerAddress::find($addressId);
        if (!$address || $address->customer_id !== $customer->id) {
            return response(['error' => 'آدرس یافت نشد یا شما دسترسی ندارید'], 404);
        }

        $cart = Cart::where('customer_id', $customer->id)
            ->where('atelier_id', $atelierId)
            ->where('status', Cart::STATUS_PENDING)
            ->first();

        if (!$cart) {
            return response([
                'error' => 'سبد خرید یافت نشد'
            ], 404);
        }

        // تعیین آدرس برای سبد خرید
        $cart->update(['address_id' => $addressId]);

        $cart->load(['items.product.images', 'items.product.categories', 'address']);

        return response([
            'message' => 'آدرس برای سبد خرید تعیین شد',
            'cart' => $cart,
            'items' => $cart->items,
            'total' => $cart->total,
            'items_count' => $cart->items_count
        ]);
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
        $atelierId = $this->customerShopAtelierId($customer);

        $cart = Cart::where('customer_id', $customer->id)
            ->where('atelier_id', $atelierId)
            ->where('status', Cart::STATUS_PENDING)
            ->first();

        if (!$cart) {
            return response([
                'error' => 'سبد خرید یافت نشد'
            ], 404);
        }

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
        $atelierId = $this->customerShopAtelierId($customer);
        $this->bindSettingContextForCustomer($customer);
        $useCredit = $request->input('use_credit', false);

        $cart = Cart::where('customer_id', $customer->id)
            ->where('atelier_id', $atelierId)
            ->where('status', Cart::STATUS_PENDING)
            ->with(['items.product'])
            ->first();

        if (!$cart) {
            return response([
                'error' => 'سبد خرید یافت نشد'
            ], 404);
        }

        // بررسی اینکه اطلاعات ارسال کامل است
        if ((!$cart->shipping_name || !$cart->shipping_address || !$cart->shipping_phone) && !$cart->address_id) {
            return response([
                'error' => 'اطلاعات ارسال کامل نیست. لطفاً ابتدا آدرس را انتخاب کنید یا اطلاعات ارسال را تکمیل کنید.'
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
            // Load address if set
            if ($cart->address_id) {
                $cart->load('address');
            }

            // محاسبه مجموع مبلغ خرید
            $originalTotalAmount = $cart->total;
            $phone = $cart->address_id ? $cart->address->phone : $cart->shipping_phone;

            $creditUsed = 0;
            $userShiksho = null;

            // اگر کاربر می‌خواهد از اعتبار استفاده کند
            if ($phone && $useCredit) {
                $userShiksho = UserShiksho::where('phone', $phone)
                    ->where('atelier_id', $atelierId)
                    ->first();
                if ($userShiksho && $userShiksho->credit > 0) {
                    $creditUsed = min($userShiksho->credit, $originalTotalAmount);
                    $userShiksho->useCredit($creditUsed);
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
                'cart_id' => $cart->id, // لینک به Cart
                'phone' => $phone,
                'total_amount' => $originalTotalAmount,
                'credit_used' => $creditUsed,
                'credit_earned' => $creditEarned,
                'atelier_id' => $atelierId,
            ]);

            // ذخیره محصولات خریداری شده
            foreach ($cart->items as $item) {
                PurchasedProduct::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'purchase_price' => $item->product->purchase_price,
                    'sale_price' => $item->price, // قیمت ذخیره شده در cart
                    'size' => $item->size, // سایز انتخاب شده
                    'color' => $item->color, // رنگ انتخاب شده
                ]);

                // کسر موجودی محصول
                $item->product->decrement('quantity', $item->quantity);
            }

            // تغییر status سبد به completed (قبل از commit)
            $cart->status = Cart::STATUS_COMPLETED;
            $cart->save();

            // اگر شماره تلفن وجود دارد
            if ($phone) {
                $enableLoyaltyCredit = \App\Models\Setting::isEnabled('enable_loyalty_credit', true);
                
                if ($enableLoyaltyCredit) {
                    // به‌روزرسانی اعتبار
                    UserShiksho::updateCredit($phone, $creditEarned, $atelierId);

                    // ارسال پیامک
                    $creditFormatted = number_format($creditEarned, 0);
                    $shopName = SmsTools::shopSmsBrand($atelierId);
                    $text = "{$shopName}\nهمراه عزیز مبلغ {$creditFormatted} تومان به اعتبار شما برای خرید بعدی اضافه شد";
                    SmsTools::sendSms($phone, $text);
                } else {
                    // اگر اعتبار غیرفعال باشد، فقط پیام ساده بفرست
                    $shopName = SmsTools::shopSmsBrand($atelierId);
                    $text = "{$shopName}\nبا تشکر از خرید شما";
                    SmsTools::sendSms($phone, $text);
                }

                // ثبت شماره تلفن در جدول customer_phones
                CustomerPhone::createNewPhone($phone);
            }

            DB::commit();

            // بارگذاری روابط بعد از commit - استفاده از find برای اطمینان از دریافت آخرین وضعیت
            $purchase->refresh();
            $purchase->load('purchasedProducts.product');
            
            $cart = Cart::with(['customer', 'items.product.images', 'items.product.categories'])->find($cart->id);

            return response([
                'message' => 'سفارش با موفقیت ثبت شد',
                'purchase' => $purchase,
                'cart' => $cart
            ], 200);

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
        $atelierId = $this->customerShopAtelierId($customer);

        $cart = Cart::where('customer_id', $customer->id)
            ->where('atelier_id', $atelierId)
            ->where('status', Cart::STATUS_PENDING)
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

    /**
     * لیست سفارشات مشتری (فقط سفارشات خودش)
     */
    public function myOrders(Request $request)
    {
        $customer = $request->user();
        $atelierId = $this->customerShopAtelierId($customer);

        $query = Cart::where('customer_id', $customer->id)
            ->where('atelier_id', $atelierId)
            ->where('status', '!=', Cart::STATUS_PENDING)
            ->with(['items.product.images', 'items.product.categories'])
            ->orderBy('id', 'desc');

        // فیلتر بر اساس status
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        // فیلتر بر اساس تاریخ
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        // دریافت تعداد آیتم در هر صفحه از request (پیش‌فرض 20)
        $perPage = $request->input('per_page', 20);
        
        $orders = $query->paginate($perPage);
        
        // حفظ مسیر URL برای pagination
        $orders->withPath(url()->current());
        
        return response($orders);
    }

    /**
     * نمایش جزئیات یک سفارش مشتری (فقط سفارشات خودش)
     */
    public function showOrder(Request $request, $cartId)
    {
        $customer = $request->user();
        $atelierId = $this->customerShopAtelierId($customer);

        // پیدا کردن cart
        $cart = Cart::where('id', $cartId)
            ->where('atelier_id', $atelierId)
            ->where('status', '!=', Cart::STATUS_PENDING)
            ->first();

        // بررسی اینکه cart وجود دارد
        if (!$cart) {
            return response([
                'error' => 'سفارش یافت نشد'
            ], 404);
        }

        // بررسی اینکه cart متعلق به این مشتری است
        if ($cart->customer_id !== $customer->id) {
            return response([
                'error' => 'شما دسترسی به این سفارش ندارید'
            ], 403);
        }

        $cart->load(['items.product.images', 'items.product.categories']);
        
        return response($cart);
    }
}

