<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\User;
use App\Models\Purchase;
use App\Models\PurchasedProduct;
use App\Models\UserShiksho;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    /**
     * بررسی اینکه کاربر یک ادمین است (نه Customer)
     */
    private function checkAdmin(Request $request)
    {
        $user = $request->user();
        
        // بررسی اینکه کاربر یک Customer نیست
        if ($user instanceof Customer) {
            return response([
                'error' => 'این endpoint فقط برای ادمین است'
            ], 403);
        }
        
        // بررسی اینکه کاربر یک User (ادمین) است
        if (!($user instanceof User)) {
            return response([
                'error' => 'دسترسی غیرمجاز'
            ], 403);
        }
        
        return null;
    }

    /**
     * لیست سفارشات اینترنتی
     */
    public function index(Request $request)
    {
        // بررسی دسترسی ادمین
        $adminCheck = $this->checkAdmin($request);
        if ($adminCheck) {
            return $adminCheck;
        }
        $query = Cart::where('status', '!=', Cart::STATUS_PENDING)
            ->with(['customer', 'items.product.images', 'items.product.categories'])
            ->orderBy('id', 'desc');

        // فیلتر بر اساس status
        if ($request->has('status')) {
            $status = $request->input('status');
            // اگر آرایه است (چند status)
            if (is_array($status)) {
                $query->whereIn('status', $status);
            } else {
                // اگر رشته است (یک status)
                $query->where('status', $status);
            }
        }
        
        // فیلترهای جداگانه برای status های خاص
        if ($request->has('shipped')) {
            $query->where('status', Cart::STATUS_SHIPPED);
        }
        if ($request->has('completed')) {
            $query->where('status', Cart::STATUS_COMPLETED);
        }
        // فیلتر برای ارسال شده یا تکمیل شده
        if ($request->has('shipped_or_completed')) {
            $query->whereIn('status', [Cart::STATUS_SHIPPED, Cart::STATUS_COMPLETED]);
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
    public function show(Request $request, Cart $cart)
    {
        // بررسی دسترسی ادمین
        $adminCheck = $this->checkAdmin($request);
        if ($adminCheck) {
            return $adminCheck;
        }

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
     * تغییر status سفارش (مثلاً از completed به shipped یا cancelled)
     */
    public function updateStatus(Request $request, Cart $cart)
    {
        // بررسی دسترسی ادمین
        $adminCheck = $this->checkAdmin($request);
        if ($adminCheck) {
            return $adminCheck;
        }

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
        $newStatus = $request->input('status');

        // اگر می‌خواهیم به cancelled تغییر دهیم
        if ($newStatus === Cart::STATUS_CANCELLED && $oldStatus !== Cart::STATUS_CANCELLED) {
            DB::beginTransaction();
            try {
                // پیدا کردن Purchase مربوط به این Cart
                $phone = $cart->shipping_phone;
                if ($phone) {
                    // بارگذاری items
                    $cart->load('items');
                    
                    // پیدا کردن Purchase بر اساس phone و total_amount و created_at
                    // (چون در completeOrder، Purchase با همین اطلاعات ایجاد می‌شود)
                    $totalAmount = $cart->total;
                    $purchase = Purchase::where('phone', $phone)
                        ->where('total_amount', $totalAmount)
                        ->whereDate('created_at', $cart->created_at->toDateString())
                        ->orderBy('created_at', 'desc')
                        ->first();
                    
                    // اگر پیدا نشد، با استفاده از product_id های Cart سعی می‌کنیم
                    if (!$purchase) {
                        $cart->load('items');
                        $productIds = $cart->items->pluck('product_id')->toArray();
                        
                        if (!empty($productIds)) {
                            $purchasedProduct = PurchasedProduct::whereIn('product_id', $productIds)
                                ->whereHas('purchase', function($q) use ($phone, $cart) {
                                    $q->where('phone', $phone)
                                      ->whereDate('created_at', $cart->created_at->toDateString());
                                })
                                ->first();
                            
                            if ($purchasedProduct) {
                                $purchase = $purchasedProduct->purchase;
                            }
                        }
                    }

                    if ($purchase) {
                        // بارگذاری purchasedProducts
                        $purchase->load('purchasedProducts');
                        
                        // برگرداندن موجودی محصولات
                        $cart->load('items.product');
                        foreach ($cart->items as $item) {
                            if ($item->product) {
                                $item->product->increment('quantity', $item->quantity);
                            }
                        }

                        // برگرداندن اعتبار استفاده شده
                        if ($purchase->credit_used > 0) {
                            $userShiksho = UserShiksho::where('phone', $phone)->first();
                            if ($userShiksho) {
                                $userShiksho->credit += $purchase->credit_used;
                                $userShiksho->save();
                            }
                        }

                        // کم کردن اعتبار کسب شده
                        if ($purchase->credit_earned > 0) {
                            $userShiksho = UserShiksho::where('phone', $phone)->first();
                            if ($userShiksho && $userShiksho->credit >= $purchase->credit_earned) {
                                $userShiksho->credit -= $purchase->credit_earned;
                                $userShiksho->save();
                            }
                        }

                        // حذف PurchasedProduct های مربوط به این Purchase
                        // (برای اینکه در لیست خریدها نمایش داده نشوند)
                        foreach ($purchase->purchasedProducts as $purchasedProduct) {
                            $purchasedProduct->delete();
                        }
                        
                        // حذف Purchase
                        $purchase->delete();
                    }
                }

                // تغییر status
                $cart->update([
                    'status' => $newStatus
                ]);

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                return response([
                    'error' => 'خطا در لغو سفارش',
                    'message' => $e->getMessage()
                ], 500);
            }
        } else {
            // برای تغییر status به completed یا shipped
            $cart->update([
                'status' => $newStatus
            ]);

            // اگر status به shipped تغییر کرد و Purchase وجود ندارد، Purchase ایجاد کن
            if ($newStatus === Cart::STATUS_SHIPPED && $oldStatus !== Cart::STATUS_SHIPPED) {
                // بررسی اینکه آیا Purchase برای این Cart وجود دارد یا نه
                $existingPurchase = Purchase::where('cart_id', $cart->id)->first();
                
                if (!$existingPurchase) {
                    DB::beginTransaction();
                    try {
                        $cart->load(['items.product', 'customer']);
                        $phone = $cart->shipping_phone;
                        
                        if ($phone && $cart->items->count() > 0) {
                            // محاسبه مجموع مبلغ خرید
                            $originalTotalAmount = $cart->total;
                            
                            // محاسبه credit_used و credit_earned (اگر در Cart ذخیره نشده)
                            $creditUsed = 0;
                            $creditEarned = 0;
                            
                            $enableLoyaltyCredit = \App\Models\Setting::isEnabled('enable_loyalty_credit', true);
                            if ($phone && $enableLoyaltyCredit) {
                                $creditEarned = \App\Models\UserShiksho::calculateCredit($originalTotalAmount);
                            }
                            
                            // ایجاد Purchase
                            $purchase = Purchase::create([
                                'cart_id' => $cart->id,
                                'phone' => $phone,
                                'total_amount' => $originalTotalAmount,
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
                                    'sale_price' => $item->price,
                                    'size' => $item->size,
                                    'color' => $item->color,
                                ]);
                            }
                        }
                        
                        DB::commit();
                    } catch (\Exception $e) {
                        DB::rollBack();
                        // خطا را لاگ می‌کنیم اما ادامه می‌دهیم
                        \Log::error('خطا در ایجاد Purchase هنگام تغییر وضعیت به shipped: ' . $e->getMessage());
                    }
                }
            }
        }

        $cart->load(['customer', 'items.product.images', 'items.product.categories']);

        return response([
            'message' => 'وضعیت سفارش با موفقیت تغییر کرد',
            'cart' => $cart,
            'old_status' => $oldStatus,
            'new_status' => $cart->status
        ]);
    }

    /**
     * تعداد سفارشات تکمیل شده
     */
    public function completedOrdersCount(Request $request)
    {
        // بررسی دسترسی ادمین
        $adminCheck = $this->checkAdmin($request);
        if ($adminCheck) {
            return $adminCheck;
        }

        $count = Cart::where('status', Cart::STATUS_COMPLETED)->count();

        return response([
            'count' => $count
        ]);
    }
}

