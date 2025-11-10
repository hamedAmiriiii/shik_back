<?php

namespace App\Http\Controllers;

use App\Models\PurchasedProduct;
use App\Models\Product;
use App\Models\CustomerPhone;
use Illuminate\Http\Request;
use App\Tools\SmsTools;


class PurchasedProductController extends Controller
{
    public function index()
    {
        return response(PurchasedProduct::with('product')->orderBy('id', 'desc')->paginate(), 200);
    }

    public function store(Request $request)
    {
        $request->validate([
            'phone'=>'required',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
            'products.*.purchase_price' => 'required|numeric|min:0',
        ]);

        $purchasedProducts = [];

        foreach ($request->input('products') as $productData) {
            $purchasedProducts[] = PurchasedProduct::create($productData);
        }

        CustomerPhone::createNewPhone($request->get("phone"));

        $text = "با تشکر از خرید شما";

    SmsTools::sendSms($phone, $text);

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
