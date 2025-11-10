<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use http\Env\Response;

class ProductController extends Controller
{
    /**
     * نمایش لیست محصولات
     */
    public function index(Request $request)
    {
        $products = Product::orderBy('id', 'desc')->paginate(200);
        return response($products);
    }

    /**
     * افزودن محصول جدید
     */
    public function store(Request $request)
    {
        $fields = $request->validate([
            "name" => "required|string|max:255",
            "purchase_price" => "required|numeric|min:0",
            "sale_price" => "required|numeric|min:0",
            "quantity" => "required|integer|min:0",
            'barcode' => 'required|string|unique:products|max:255',
        ]);
        $product = Product::create($fields);
        return response($product, 201);
    }

    /**
     * نمایش جزئیات یک محصول
     */
    public function show(Product $product)
    {
        return response($product);
    }

    /**
     * ویرایش اطلاعات محصول
     */
    public function update(Request $request, Product $product)
    {
        $fields = $request->validate([
            "name" => "required|string|max:255",
            "purchase_price" => "required|numeric|min:0",
            "sale_price" => "required|numeric|min:0",
            "quantity" => "required|integer|min:0",
            'barcode' => 'required|string|unique:products|max:255',
        ]);

        $product->update($fields);
        return response($product);
    }

    /**
     * حذف محصول
     */
    public function destroy(Product $product)
    {
        $product->delete();
        return response(['message' => 'محصول با موفقیت حذف شد']);
    }
}
