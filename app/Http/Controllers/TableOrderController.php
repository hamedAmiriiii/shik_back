<?php

namespace App\Http\Controllers;

use App\Models\CustomerPhone;
use App\Models\Purchase;
use App\Models\PurchasedProduct;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShopTable;
use App\Models\UserShiksho;
use App\Tools\PhoneTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TableOrderController extends Controller
{
    /**
     * ثبت سفارش پای میز (بدون نیاز به لاگین مشتری)
     * POST /api/{shop}/table-order
     *
     * body: {
     *   table_number: 1,
     *   phone: "0912...",
     *   use_credit: true,
     *   products: [{product_id: 5, quantity: 2}, ...]
     * }
     */
    public function store(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        Setting::setShopContext($atelierId);

        if ($request->filled('phone')) {
            $request->merge([
                'phone' => PhoneTools::normalizeIranPhone($request->input('phone')),
            ]);
        }

        $request->validate([
            'table_number' => 'required|integer|min:1',
            'phone' => [
                Rule::requiredIf($request->boolean('use_credit')),
                'nullable',
                'string',
                'regex:/^09\d{9}$/',
            ],
            'use_credit' => 'nullable|boolean',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.quantity' => 'required|numeric|min:0.001',
            'products.*.size' => 'nullable|string|max:100',
            'products.*.color' => 'nullable|string|max:100',
            'note' => 'nullable|string|max:500',
        ]);

        $tableNumber = $request->table_number;
        $phone = $request->input('phone');
        $useCredit = $request->boolean('use_credit');

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

            $creditUsed = 0.0;
            if ($phone && $useCredit) {
                $userShiksho = UserShiksho::where('phone', $phone)
                    ->where('atelier_id', $atelierId)
                    ->first();
                if ($userShiksho && $userShiksho->credit > 0) {
                    $creditUsed = min((float) $userShiksho->credit, $totalAmount);
                    $userShiksho->useCredit($creditUsed);
                }
            }

            $purchase = Purchase::create([
                'atelier_id' => $atelierId,
                'shop_table_id' => $shopTable->id,
                'table_label' => $tableLabel,
                'phone' => $phone,
                'total_amount' => $totalAmount,
                'credit_used' => $creditUsed,
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

            if ($phone) {
                CustomerPhone::createNewPhone($phone);
            }

            DB::commit();

            $purchase->load(['purchasedProducts.product', 'shopTable']);

            $remainingCredit = 0.0;
            if ($phone) {
                $remainingCredit = (float) (UserShiksho::where('phone', $phone)
                    ->where('atelier_id', $atelierId)
                    ->value('credit') ?? 0);
            }

            return response()->json([
                'message' => 'سفارش پای میز ثبت شد و منتظر پرداخت است',
                'purchase' => app(GuestCustomerController::class)->guestPurchasePayload($purchase),
                'table' => $shopTable,
                'credit_used' => $creditUsed,
                'payable_amount' => $purchase->payableAmount(),
                'credit' => $remainingCredit,
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
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $shopTable = ShopTable::where('atelier_id', $atelierId)
            ->where('table_number', $tableNumber)
            ->where('is_active', true)
            ->firstOrFail();

        return response()->json([
            'table' => $shopTable,
        ]);
    }
}
