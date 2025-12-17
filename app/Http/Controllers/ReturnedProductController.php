<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ReturnedProduct;
use Illuminate\Http\Request;

class ReturnedProductController extends Controller
{
    /**
     * برگشت کالا بر اساس بارکد
     */
    public function store(Request $request)
    {
        $request->validate([
            'barcode' => 'required|string',
        ]);

        // پیدا کردن محصول بر اساس بارکد
        $product = Product::where('barcode', $request->input('barcode'))->first();

        if (!$product) {
            return response(['error' => 'محصولی با این بارکد یافت نشد'], 404);
        }

        // افزایش موجودی محصول
        $product->increment('quantity', 1);

        // ثبت برگشت کالا
        $returnedProduct = ReturnedProduct::create([
            'product_id' => $product->id,
            'sale_price' => $product->sale_price, // قیمت فروش فعلی محصول
        ]);

        // بارگذاری اطلاعات محصول
        $returnedProduct->load('product');

        return response([
            'message' => 'کالا با موفقیت برگشت داده شد',
            'returned_product' => $returnedProduct,
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'barcode' => $product->barcode,
                'new_quantity' => $product->quantity,
            ]
        ], 201);
    }

    /**
     * لیست برگشتی‌ها
     */
    public function index(Request $request)
    {
        $query = ReturnedProduct::with('product')->orderBy('id', 'desc');

        // جستجو بر اساس searchFilterModel
        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel) {
            $query->where(function($q) use ($searchDataModel) {
                if (is_object($searchDataModel)) {
                    // جستجو بر اساس بارکد محصول
                    if (isset($searchDataModel->barcode)) {
                        $q->whereHas('product', function($productQuery) use ($searchDataModel) {
                            $productQuery->where('barcode', 'like', '%' . $searchDataModel->barcode . '%');
                        });
                    }
                    // جستجو بر اساس نام محصول
                    if (isset($searchDataModel->product_name)) {
                        $q->orWhereHas('product', function($productQuery) use ($searchDataModel) {
                            $productQuery->where('name', 'like', '%' . $searchDataModel->product_name . '%');
                        });
                    }
                } else if (is_string($searchDataModel)) {
                    // اگر یک رشته ساده بود، در بارکد و نام محصول جستجو می‌کند
                    $q->whereHas('product', function($productQuery) use ($searchDataModel) {
                        $productQuery->where('barcode', 'like', '%' . $searchDataModel . '%')
                            ->orWhere('name', 'like', '%' . $searchDataModel . '%');
                    });
                }
            });
        }

        $returnedProducts = $query->paginate();
        return response($returnedProducts, 200);
    }
}

