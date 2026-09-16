<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ProductStockNotifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ProductStockNotifyController extends Controller
{
    public function store(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if (! Schema::hasTable('product_stock_notify_requests')) {
            return response(['message' => 'جدول اعلان موجودی هنوز ساخته نشده است.'], 503);
        }

        $validated = $request->validate([
            'product_id' => 'required|integer|min:1',
            'phone' => 'required|string|digits:11',
        ]);

        $phone = $validated['phone'];
        if (! preg_match('/^09\d{9}$/', $phone)) {
            return response(['message' => 'شماره موبایل معتبر نیست'], 422);
        }

        $product = Product::query()
            ->where('atelier_id', $atelierId)
            ->where('id', $validated['product_id'])
            ->first();

        if (! $product) {
            return response(['message' => 'محصول یافت نشد'], 404);
        }

        $qty = (float) ($product->quantity ?? 0);
        if ($qty > 0) {
            return response(['message' => 'این کالا هم‌اکنون موجود است'], 422);
        }

        $row = ProductStockNotifyService::subscribe($atelierId, (int) $product->id, $phone);

        return response([
            'message' => 'درخواست ثبت شد؛ با موجود شدن کالا پیامک ارسال می‌شود',
            'data' => $row,
            'already_exists' => ! $row->wasRecentlyCreated,
        ], $row->wasRecentlyCreated ? 201 : 200);
    }
}
