<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * لیست سفارشات اینترنتی
     */
    public function index(Request $request)
    {
        $query = Cart::where('status', '!=', Cart::STATUS_PENDING)
            ->with(['customer', 'items.product.images', 'items.product.categories'])
            ->orderBy('id', 'desc');

        // فیلتر بر اساس status
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        // جستجو بر اساس searchFilterModel
        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel) {
            $query->where(function($q) use ($searchDataModel) {
                if (is_object($searchDataModel)) {
                    // جستجو بر اساس شماره تلفن
                    if (isset($searchDataModel->phone)) {
                        $q->where('shipping_phone', 'like', '%' . $searchDataModel->phone . '%');
                    }
                    // جستجو بر اساس نام مشتری
                    if (isset($searchDataModel->name)) {
                        $q->where(function($nameQuery) use ($searchDataModel) {
                            $nameQuery->where('shipping_name', 'like', '%' . $searchDataModel->name . '%')
                                     ->orWhere('shipping_last_name', 'like', '%' . $searchDataModel->name . '%');
                        });
                    }
                    // جستجو بر اساس نام و نام خانوادگی
                    if (isset($searchDataModel->full_name)) {
                        $q->where(function($nameQuery) use ($searchDataModel) {
                            $nameQuery->where('shipping_name', 'like', '%' . $searchDataModel->full_name . '%')
                                     ->orWhere('shipping_last_name', 'like', '%' . $searchDataModel->full_name . '%');
                        });
                    }
                } else if (is_string($searchDataModel)) {
                    // اگر یک رشته ساده بود، در شماره تلفن، نام و نام خانوادگی جستجو می‌کند
                    $q->where('shipping_phone', 'like', '%' . $searchDataModel . '%')
                      ->orWhere('shipping_name', 'like', '%' . $searchDataModel . '%')
                      ->orWhere('shipping_last_name', 'like', '%' . $searchDataModel . '%');
                }
            });
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
     * نمایش جزئیات یک سفارش
     */
    public function show(Cart $cart)
    {
        // بررسی اینکه cart یک سفارش است (نه pending)
        if ($cart->status === Cart::STATUS_PENDING) {
            return response([
                'error' => 'این یک سفارش نیست'
            ], 400);
        }

        $cart->load(['customer', 'items.product.images', 'items.product.categories']);
        
        return response($cart);
    }

    /**
     * تغییر status سفارش (مثلاً از completed به shipped)
     */
    public function updateStatus(Request $request, Cart $cart)
    {
        $request->validate([
            'status' => 'required|string|in:' . Cart::STATUS_COMPLETED . ',' . Cart::STATUS_SHIPPED . ',' . Cart::STATUS_CANCELLED
        ]);

        // بررسی اینکه cart یک سفارش است (نه pending)
        if ($cart->status === Cart::STATUS_PENDING) {
            return response([
                'error' => 'این یک سفارش نیست'
            ], 400);
        }

        $oldStatus = $cart->status;
        $cart->update([
            'status' => $request->input('status')
        ]);

        $cart->load(['customer', 'items.product.images', 'items.product.categories']);

        return response([
            'message' => 'وضعیت سفارش با موفقیت تغییر کرد',
            'cart' => $cart,
            'old_status' => $oldStatus,
            'new_status' => $cart->status
        ]);
    }
}

