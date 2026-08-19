<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\PurchasedProduct;
use App\Models\Product;
use App\Models\ShopTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TableOrderController extends Controller
{
    /**
     * ثبت سفارش پای میز (بدون نیاز به لاگین مشتری)
     * POST /api/{shop}/table-order
     *
     * body: {
     *   table_number: 1,
     *   products: [{product_id: 5, quantity: 2}, ...]
     * }
     */
    public function store(Request $request)
    {
        $atelierId = (int) $request->attributes->get('atelier_id');

        $request->validate([
            'table_number' => 'required|integer|min:1',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.quantity' => 'required|numeric|min:0.001',
            'products.*.size' => 'nullable|string|max:100',
            'products.*.color' => 'nullable|string|max:100',
            'note' => 'nullable|string|max:500',
        ]);

        $tableNumber = $request->table_number;

        // پیدا کردن یا ساخت خودکار میز
        $shopTable = ShopTable::firstOrCreate(
            ['atelier_id' => $atelierId, 'table_number' => $tableNumber],
            ['is_active' => true]
        );

        // خواندن محصولات و بررسی تعلق به فروشگاه
        $productIds = array_column($request->products, 'product_id');
        $products = Product::whereIn('id', $productIds)
            ->where('atelier_id', $atelierId)
            ->get()
            ->keyBy('id');

        if ($products->count() !== count(array_unique($productIds))) {
            return response()->json(['message' => 'یک یا چند محصول در این فروشگاه وجود ندارد'], 422);
        }

        DB::beginTransaction();
        try {
            $totalAmount = 0;
            $lines = [];

            foreach ($request->products as $item) {
                $product = $products->get($item['product_id']);
                $qty = (float) $item['quantity'];
                $price = (float) $product->sale_price;
                $totalAmount += $price * $qty;

                $lines[] = [
                    'product' => $product,
                    'quantity' => $qty,
                    'price' => $price,
                    'size' => $item['size'] ?? null,
                    'color' => $item['color'] ?? null,
                ];
            }

            $tableLabel = $shopTable->display_name;

            $purchase = Purchase::create([
                'atelier_id' => $atelierId,
                'shop_table_id' => $shopTable->id,
                'table_label' => $tableLabel,
                'phone' => null,
                'total_amount' => $totalAmount,
                'payment_type' => 'debt',
                'is_debt_settled' => false,
                'debt_settlement_note' => $request->note,
            ]);

            foreach ($lines as $line) {
                PurchasedProduct::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $line['product']->id,
                    'quantity' => $line['quantity'],
                    'purchase_price' => $line['product']->purchase_price,
                    'sale_price' => $line['price'],
                    'size' => $line['size'],
                    'color' => $line['color'],
                ]);

                // کسر موجودی
                $line['product']->decrement('quantity', $line['quantity']);
            }

            DB::commit();

            $purchase->load('purchasedProducts.product');

            return response()->json([
                'message' => 'سفارش پای میز ثبت شد و منتظر پرداخت است',
                'purchase' => $purchase,
                'table' => $shopTable,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'خطا در ثبت سفارش',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * لیست سفارش‌های پای میز در انتظار پرداخت (برای ادمین فروشگاه)
     * GET /api/table-orders?settled=0
     */
    public function index(Request $request)
    {
        $atelierId = (int) auth()->user()->atelier_id;

        $query = Purchase::where('atelier_id', $atelierId)
            ->whereNotNull('shop_table_id')
            ->with(['purchasedProducts.product']);

        $settled = $request->query('settled', '0');
        if ($settled === '0') {
            $query->where('is_debt_settled', false);
        } elseif ($settled === '1') {
            $query->where('is_debt_settled', true);
        }

        if ($request->filled('table_number')) {
            $query->whereHas('shopTable', function ($q) use ($request) {
                $q->where('table_number', $request->table_number);
            });
        }

        $orders = $query->orderByDesc('created_at')->paginate(30);

        return response()->json($orders);
    }

    /**
     * نمایش اطلاعات یک میز + سفارش‌های فعال آن (برای صفحه QR)
     * GET /api/{shop}/tables/{table_number}
     */
    public function tableInfo(Request $request, int $tableNumber)
    {
        $atelierId = (int) $request->attributes->get('atelier_id');

        $shopTable = ShopTable::where('atelier_id', $atelierId)
            ->where('table_number', $tableNumber)
            ->where('is_active', true)
            ->firstOrFail();

        return response()->json([
            'table' => $shopTable,
        ]);
    }
}
