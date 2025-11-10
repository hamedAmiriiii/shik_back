<?php

namespace App\Http\Controllers;

use App\Models\PurchasedProduct;
use App\Models\Product;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
// use App\Tools\SmsTools;


class PurchasedProductController extends Controller
{
    public function index(Request $request)
{
    $query = PurchasedProduct::with('product')->orderBy('id', 'desc');

    // فیلتر تاریخ
    if ($request->has('filter')) {
        if ($request->filter === 'today') {
            $query->whereDate('created_at', Carbon::today());
        } elseif ($request->filter === 'month') {
            $query->whereMonth('created_at', Carbon::now()->month)
                  ->whereYear('created_at', Carbon::now()->year);
        }
    }

    $items = $query->paginate();

    // محاسبه مجموع قیمت خرید برای آیتم‌های این صفحه
    $total = PurchasedProduct::whereIn('id', $items->pluck('id'))
        ->select(DB::raw('SUM(quantity * purchase_price) as total'))
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
                        // 'phone'=>'required',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
            'products.*.purchase_price' => 'required|numeric|min:0',
        ]);


        $purchasedProducts = [];

        foreach ($request->input('products') as $productData) {
            $purchasedProducts[] = PurchasedProduct::create($productData);
        }
//  $text = "با تشکر از خرید شما";

//     SmsTools::sendSms($phone, $text);
        return response($purchasedProducts, 201);
    }

    public function show(PurchasedProduct $purchasedProduct)
    {
        return response($purchasedProduct->load('product'), 200);
    }

    public function update(Request $request, PurchasedProduct $purchasedProduct)
    {
        $request->validate([
            'quantity' => 'sometimes|required|integer|min:1',
            'purchase_price' => 'sometimes|required|numeric|min:0',
        ]);

        $purchasedProduct->update($request->only(['quantity', 'purchase_price']));

        return response($purchasedProduct, 200);
    }

    public function destroy(PurchasedProduct $purchasedProduct)
    {
        $purchasedProduct->delete();
        return response(['message' => 'محصول خریداری‌شده حذف شد'], 200);
    }
}
