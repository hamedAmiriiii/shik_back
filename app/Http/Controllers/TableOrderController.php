<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Setting;
use App\Models\ShopTable;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use App\Models\UserShiksho;
use App\Services\TableOrderCheckoutService;
use App\Tools\ImageTools;
use App\Tools\PhoneTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class TableOrderController extends Controller
{
    /**
     * ثبت سفارش پای میز — هنوز خرید نیست، تا پرداخت در table_orders می‌ماند.
     * POST /api/{shop}/table-order
     */
    public function store(Request $request, $shop = null)
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
            'payment_method' => 'required|string|in:online,card_to_card,pos',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
            'receipt_base64' => 'nullable|string',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.quantity' => 'required|numeric|min:0.001',
            'products.*.size' => 'nullable|string|max:100',
            'products.*.color' => 'nullable|string|max:100',
            'note' => 'nullable|string|max:500',
        ]);

        if ($this->requestHasReceipt($request) && $request->input('payment_method') !== TableOrder::METHOD_CARD_TO_CARD) {
            return response()->json(['message' => 'ارسال رسید فقط برای کارت به کارت است.'], 422);
        }

        $shopTable = ShopTable::firstOrCreate(
            ['atelier_id' => $atelierId, 'table_number' => $request->table_number],
            ['is_active' => true]
        );

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
                if ((float) $product->quantity < $qty) {
                    DB::rollBack();

                    return response()->json([
                        'message' => "موجودی محصول «{$product->name}» کافی نیست.",
                    ], 400);
                }

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

            $order = TableOrder::create([
                'atelier_id' => $atelierId,
                'shop_table_id' => $shopTable->id,
                'table_label' => $shopTable->display_name,
                'phone' => $request->input('phone'),
                'note' => $request->note,
                'total_amount' => $totalAmount,
                'use_credit' => $request->boolean('use_credit'),
                'payment_method' => $request->input('payment_method'),
                'status' => TableOrder::STATUS_PENDING,
            ]);

            if ($this->requestHasReceipt($request)) {
                $this->saveReceiptToOrder($order, $request);
            }

            foreach ($lines as $line) {
                TableOrderItem::create([
                    'table_order_id' => $order->id,
                    'product_id' => $line['product']->id,
                    'quantity' => $line['quantity'],
                    'purchase_price' => $line['product']->purchase_price,
                    'sale_price' => $line['price'],
                    'size' => $line['size'],
                    'color' => $line['color'],
                ]);
            }

            DB::commit();

            $credit = 0.0;
            $phone = $order->phone;
            if ($phone) {
                $credit = (float) (UserShiksho::where('phone', $phone)
                    ->where('atelier_id', $atelierId)
                    ->value('credit') ?? 0);
            }

            return response()->json([
                'message' => 'سفارش ثبت شد و منتظر پرداخت است',
                'table_order' => $order->fresh(['items.product', 'shopTable'])->toPublicArray(),
                'table' => $shopTable,
                'credit' => $credit,
                'payment_methods' => TableOrder::paymentMethodsForApi(),
            ], 201);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'خطا در ثبت سفارش',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * لیست سفارش‌های پای میز (برای پرسنل فروشگاه)
     * GET /api/table-orders?status=pending
     */
    public function index(Request $request)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $query = TableOrder::where('atelier_id', $atelierId)
            ->with(['items.product', 'shopTable', 'purchase']);

        $status = $request->query('status');
        if (! $status) {
            $settled = $request->query('settled', '0');
            $status = $settled === '1' ? TableOrder::STATUS_PAID : TableOrder::STATUS_PENDING;
        }

        if (in_array($status, [TableOrder::STATUS_PENDING, TableOrder::STATUS_PAID, TableOrder::STATUS_CANCELLED], true)) {
            $query->where('status', $status);
        }

        if ($request->filled('table_number')) {
            $query->whereHas('shopTable', function ($q) use ($request) {
                $q->where('table_number', $request->table_number);
            });
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->has('has_receipt')) {
            if ($request->boolean('has_receipt')) {
                $query->whereNotNull('receipt_path');
            } else {
                $query->whereNull('receipt_path');
            }
        }

        $orders = $query->orderByDesc('id')->paginate(30);
        $payload = $orders->toArray();
        $payload['data'] = collect($orders->items())->map(
            fn (TableOrder $order) => $order->toPublicArray()
        )->values()->all();

        return response()->json($payload);
    }

    /**
     * تعداد سفارش‌های پای میز رسیدگی‌نشده — مناسب پولینگ هر ۳۰ ثانیه
     * GET /api/table-orders/pending-count
     */
    public function pendingCount(Request $request)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $base = TableOrder::query()
            ->where('atelier_id', $atelierId)
            ->where('status', TableOrder::STATUS_PENDING);

        $count = (clone $base)->count();
        $withReceipt = (clone $base)->whereNotNull('receipt_path')->count();
        $latest = (clone $base)->orderByDesc('id')->first(['id', 'created_at', 'updated_at']);

        $byMethod = (clone $base)
            ->selectRaw('payment_method, COUNT(*) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        $byTable = (clone $base)
            ->selectRaw('shop_table_id, table_label, COUNT(*) as total')
            ->groupBy('shop_table_id', 'table_label')
            ->orderByDesc('total')
            ->get()
            ->map(function ($row) {
                return [
                    'shop_table_id' => (int) $row->shop_table_id,
                    'table_label' => $row->table_label,
                    'count' => (int) $row->total,
                ];
            })
            ->values();

        return response()->json([
            'count' => $count,
            'with_receipt' => $withReceipt,
            'latest_id' => $latest ? (int) $latest->id : null,
            'latest_at' => $latest ? $latest->updated_at : null,
            'by_payment_method' => [
                TableOrder::METHOD_ONLINE => (int) ($byMethod[TableOrder::METHOD_ONLINE] ?? 0),
                TableOrder::METHOD_CARD_TO_CARD => (int) ($byMethod[TableOrder::METHOD_CARD_TO_CARD] ?? 0),
                TableOrder::METHOD_POS => (int) ($byMethod[TableOrder::METHOD_POS] ?? 0),
            ],
            'by_table' => $byTable,
        ]);
    }

    /**
     * جزئیات یک سفارش پای میز (برای ادمین — شامل رسید)
     * GET /api/table-orders/{tableOrder}
     */
    public function show(Request $request, TableOrder $tableOrder)
    {
        $this->requireStaffShopUser($request);
        $this->assertModelBelongsToStaffAtelier($request, $tableOrder);

        $tableOrder->load(['items.product', 'shopTable', 'purchase']);

        return response()->json($tableOrder->toPublicArray());
    }

    /**
     * پرداخت سفارش پای میز — از این لحظه Purchase ساخته می‌شود.
     * POST /api/table-orders/{tableOrder}/pay
     */
    public function pay(Request $request, TableOrder $tableOrder, TableOrderCheckoutService $checkout)
    {
        $this->requireStaffShopUser($request);
        $this->assertModelBelongsToStaffAtelier($request, $tableOrder);

        $request->validate([
            'card_amount' => 'nullable|numeric|min:0',
            'cash_amount' => 'nullable|numeric|min:0',
            'payment_settlement' => 'nullable|string|in:card,cash',
            'use_credit' => 'nullable|boolean',
            'note' => 'nullable|string|max:500',
        ]);

        $purchase = $checkout->pay($tableOrder, $request);
        $purchase->load(['purchasedProducts.product', 'shopTable']);
        $tableOrder->refresh()->load(['items.product', 'shopTable']);

        return response()->json([
            'message' => 'پرداخت ثبت شد و فاکتور ساخته شد',
            'table_order' => $tableOrder->toPublicArray(),
            'purchase' => $purchase,
        ], 200);
    }

    /**
     * لغو سفارش پای میزِ پرداخت‌نشده توسط ادمین
     * POST /api/table-orders/{tableOrder}/cancel
     */
    public function cancel(Request $request, TableOrder $tableOrder)
    {
        $this->requireStaffShopUser($request);
        $this->assertModelBelongsToStaffAtelier($request, $tableOrder);

        return $this->cancelPendingOrder($tableOrder, 'staff');
    }

    /**
     * لغو سفارش پای میز توسط مشتری (بدون لاگین)
     * POST /api/{shop}/table-order/{tableOrder}/cancel
     */
    public function guestCancel(Request $request, $shop, TableOrder $tableOrder)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $this->assertGuestTableOrder($tableOrder, $atelierId);

        if ($request->filled('phone')) {
            $request->merge([
                'phone' => PhoneTools::normalizeIranPhone($request->input('phone')),
            ]);
        }

        if ($tableOrder->phone) {
            $request->validate([
                'phone' => 'required|string|regex:/^09\d{9}$/',
            ]);
            if ($request->input('phone') !== $tableOrder->phone) {
                return response()->json(['message' => 'شماره موبایل با این سفارش مطابقت ندارد.'], 422);
            }
        }

        return $this->cancelPendingOrder($tableOrder, 'customer');
    }

    /**
     * لیست سفارش‌های پای میز مشتری که هنوز فاکتور نشده‌اند
     * GET /api/{shop}/table-orders?table_number=1&phone=09...
     */
    public function guestIndex(Request $request, $shop = null)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        Setting::setShopContext($atelierId);

        if ($request->filled('phone')) {
            $request->merge([
                'phone' => PhoneTools::normalizeIranPhone($request->input('phone')),
            ]);
        }

        $request->validate([
            'table_number' => 'nullable|integer|min:1',
            'phone' => 'nullable|string|regex:/^09\d{9}$/',
        ]);

        if (! $request->filled('table_number') && ! $request->filled('phone')) {
            return response()->json([
                'message' => 'شماره میز یا شماره موبایل را بفرستید.',
            ], 422);
        }

        $query = TableOrder::query()
            ->where('atelier_id', $atelierId)
            ->whereNull('purchase_id')
            ->where('status', '!=', TableOrder::STATUS_PAID)
            ->with(['items.product', 'shopTable'])
            ->orderByDesc('id');

        if ($request->filled('table_number')) {
            $query->whereHas('shopTable', function ($q) use ($request) {
                $q->where('table_number', $request->table_number);
            });
        }

        if ($request->filled('phone')) {
            $query->where('phone', $request->input('phone'));
        }

        $orders = $query->get()->map(fn (TableOrder $order) => $order->toPublicArray())->values();

        return response()->json([
            'count' => $orders->count(),
            'table_orders' => $orders,
        ]);
    }

    /**
     * مشاهده یک سفارش پای میز قبل از تبدیل به فاکتور (بدون لاگین)
     * GET /api/{shop}/table-order/{tableOrder}
     */
    public function guestShow(Request $request, $shop, TableOrder $tableOrder)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $this->assertGuestTableOrder($tableOrder, $atelierId);
        Setting::setShopContext($atelierId);

        if ($request->filled('phone')) {
            $request->merge([
                'phone' => PhoneTools::normalizeIranPhone($request->input('phone')),
            ]);
        }

        if ($tableOrder->phone && $request->filled('phone') && $request->input('phone') !== $tableOrder->phone) {
            return response()->json(['message' => 'سفارش یافت نشد'], 404);
        }

        if ($tableOrder->purchase_id || $tableOrder->status === TableOrder::STATUS_PAID) {
            return response()->json([
                'message' => 'این سفارش پرداخت شده و به فاکتور منتقل شده است.',
                'purchase_id' => $tableOrder->purchase_id,
            ], 410);
        }

        $tableOrder->load(['items.product', 'shopTable']);

        return response()->json([
            'table_order' => $tableOrder->toPublicArray(),
        ]);
    }

    /**
     * اطلاعات میز + سفارش‌های فعال (پرداخت‌نشده)
     * GET /api/{shop}/tables/{table_number}
     */
    public function tableInfo(Request $request, $shop, $tableNumber)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        Setting::setShopContext($atelierId);

        $shopTable = ShopTable::where('atelier_id', $atelierId)
            ->where('table_number', $tableNumber)
            ->where('is_active', true)
            ->firstOrFail();

        $pending = TableOrder::where('shop_table_id', $shopTable->id)
            ->where('status', TableOrder::STATUS_PENDING)
            ->with(['items.product', 'shopTable'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (TableOrder $order) => $order->toPublicArray())
            ->values();

        return response()->json([
            'table' => $shopTable,
            'pending_orders' => $pending,
            'payment_methods' => TableOrder::paymentMethodsForApi(),
        ]);
    }

    /**
     * ارسال/جایگزینی رسید کارت‌به‌کارت توسط مشتری (بدون لاگین)
     * POST /api/{shop}/table-order/{tableOrder}/receipt
     */
    public function uploadReceipt(Request $request, $shop, TableOrder $tableOrder)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $this->assertGuestTableOrder($tableOrder, $atelierId);

        if ($tableOrder->payment_method !== TableOrder::METHOD_CARD_TO_CARD) {
            return response()->json(['message' => 'ارسال رسید فقط برای کارت به کارت است.'], 422);
        }

        if (! $tableOrder->isPending()) {
            return response()->json(['message' => 'فقط برای سفارش منتظر پرداخت می‌توان رسید فرستاد.'], 422);
        }

        $request->validate([
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
            'receipt_base64' => 'nullable|string',
        ]);

        if (! $this->requestHasReceipt($request)) {
            return response()->json(['message' => 'فایل رسید را ارسال کنید.'], 422);
        }

        $this->saveReceiptToOrder($tableOrder, $request);

        return response()->json([
            'message' => 'رسید با موفقیت ثبت شد',
            'table_order' => $tableOrder->fresh(['items.product', 'shopTable'])->toPublicArray(),
        ]);
    }

    private function cancelPendingOrder(TableOrder $tableOrder, string $cancelledBy)
    {
        if (! $tableOrder->isPending()) {
            return response()->json(['message' => 'فقط سفارش منتظر پرداخت قابل لغو است.'], 422);
        }

        $tableOrder->update(['status' => TableOrder::STATUS_CANCELLED]);

        $payload = $tableOrder->fresh(['items.product', 'shopTable'])->toPublicArray();
        $payload['cancelled_by'] = $cancelledBy;

        return response()->json([
            'message' => 'سفارش لغو شد',
            'cancelled_by' => $cancelledBy,
            'table_order' => $payload,
        ]);
    }

    private function assertGuestTableOrder(TableOrder $tableOrder, int $atelierId): void
    {
        if ((int) $tableOrder->atelier_id !== $atelierId) {
            abort(response()->json(['message' => 'سفارش یافت نشد'], 404));
        }
    }

    private function requestHasReceipt(Request $request): bool
    {
        return $request->hasFile('receipt') || $request->filled('receipt_base64');
    }

    private function saveReceiptToOrder(TableOrder $order, Request $request): void
    {
        if ($order->payment_method !== TableOrder::METHOD_CARD_TO_CARD) {
            abort(response()->json(['message' => 'ارسال رسید فقط برای کارت به کارت است.'], 422));
        }

        $ext = 'jpeg';
        $content = null;

        if ($request->hasFile('receipt')) {
            $file = $request->file('receipt');
            $origExt = strtolower((string) $file->getClientOriginalExtension());
            $ext = in_array($origExt, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true) ? $origExt : 'jpeg';
            $content = file_get_contents($file->getRealPath());
        } elseif ($request->filled('receipt_base64')) {
            $raw = (string) $request->input('receipt_base64');
            if (strpos($raw, ',') !== false) {
                $header = strtolower(strstr($raw, ',', true) ?: '');
                $raw = substr($raw, strpos($raw, ',') + 1);
                if (strpos($header, 'png') !== false) {
                    $ext = 'png';
                } elseif (strpos($header, 'webp') !== false) {
                    $ext = 'webp';
                } elseif (strpos($header, 'pdf') !== false) {
                    $ext = 'pdf';
                }
            }
            $content = base64_decode($raw);
        }

        if ($content === false || $content === null || $content === '') {
            abort(response()->json(['message' => 'رسید نامعتبر است.'], 422));
        }

        if (strlen($content) > 5 * 1024 * 1024) {
            abort(response()->json(['message' => 'حجم رسید نباید بیشتر از ۵ مگابایت باشد.'], 422));
        }

        $oldPath = $order->receipt_path;
        $path = ImageTools::saveFile(
            "/table-orders/{$order->id}/receipt_".time().".{$ext}",
            $content
        );

        $order->update(['receipt_path' => $path]);

        if ($oldPath && Storage::exists('public/'.$oldPath)) {
            Storage::delete('public/'.$oldPath);
        }
    }
}
