<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\State;
use App\Models\City;
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

        // به‌روزرسانی اطلاعات ارسال
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

