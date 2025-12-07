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
            'barcode' => 'nullable|string|unique:products|max:255',
        ]);

        // اگر بارکد ارسال نشده باشد، ابتدا یک بارکد موقت ایجاد می‌کنیم
        // سپس بعد از ایجاد محصول، آن را به ID تغییر می‌دهیم
        $hasBarcode = !empty($fields['barcode']);
        if (!$hasBarcode) {
            // ایجاد یک بارکد موقت برای ایجاد محصول
            $fields['barcode'] = $this->generateTemporaryBarcode();
        }

        $product = Product::create($fields);

        // اگر بارکد ارسال نشده بود، آن را به ID محصول تغییر می‌دهیم
        if (!$hasBarcode) {
            $product->barcode = (string) $product->id;
            $product->save();
        }

        return response($product, 201);
    }

    /**
     * تولید بارکد موقت برای ایجاد محصول
     */
    private function generateTemporaryBarcode()
    {
        do {
            // استفاده از timestamp و عدد تصادفی برای تولید بارکد موقت
            $barcode = 'TMP' . time() . rand(10000, 99999);
        } while (Product::where('barcode', $barcode)->exists());

        return $barcode;
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
            'barcode' => 'required|string|unique:products,barcode,' . $product->id . '|max:255',
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
