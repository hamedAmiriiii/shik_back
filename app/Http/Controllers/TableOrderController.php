<?php

namespace App\Http\Controllers;

use App\Models\Atelier;
use App\Models\GatewayPayment;
use App\Models\Setting;
use App\Models\ShopTable;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use App\Models\UserShiksho;
use App\Services\GatewayPaymentService;
use App\Services\ShopPosSaleService;
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
        $atelierId = $this->assertShopFeature($request, \App\Services\ShopFeatureFlags::RESTAURANT_CAFE, 'سفارش حضوری برای این فروشگاه فعال نیست.');
        Setting::setShopContext($atelierId);

        $posSale = app(ShopPosSaleService::class);
        $checkout = app(TableOrderCheckoutService::class);
        $gateway = app(GatewayPaymentService::class);

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
            'payment_method' => ['required', 'string', Rule::in(TableOrder::paymentMethodKeys())],
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
            'receipt_base64' => 'nullable|string',
            'return_url' => 'nullable|string|max:1000',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'nullable|integer',
            'products.*.produced_good_id' => 'nullable|integer',
            'products.*.raw_material_id' => 'nullable|integer',
            'products.*.item_type' => 'nullable|string|in:product,produced_good,raw_material',
            'products.*.quantity' => 'required|numeric|min:0.001',
            'products.*.size' => 'nullable|string|max:100',
            'products.*.color' => 'nullable|string|max:100',
            'note' => 'nullable|string|max:500',
            'kind' => 'nullable|string|in:table,room,میز,اتاق',
        ]);

        $paymentMethod = (string) $request->input('payment_method');
        if (! TableOrder::isPaymentMethodEnabled($paymentMethod)) {
            return response()->json([
                'message' => 'این روش پرداخت برای فروشگاه فعال نیست. روش دیگری انتخاب کنید.',
                'payment_methods' => TableOrder::paymentMethodsForApi(),
            ], 422);
        }

        if ($this->requestHasReceipt($request) && $paymentMethod !== TableOrder::METHOD_CARD_TO_CARD) {
            return response()->json(['message' => 'ارسال رسید فقط برای کارت به کارت است.'], 422);
        }

        // قیمت/تخفیف از مشتری پذیرفته نمی‌شود؛ فقط شناسه و تعداد.
        $guestRows = [];
        foreach ($request->input('products', []) as $item) {
            $row = array_intersect_key((array) $item, array_flip([
                'product_id', 'produced_good_id', 'raw_material_id', 'item_type', 'quantity', 'size', 'color',
            ]));
            $isCatalogLine = ! empty($row['produced_good_id']) || ! empty($row['raw_material_id'])
                || in_array($row['item_type'] ?? null, ['produced_good', 'raw_material'], true);
            if ($isCatalogLine && ! TableOrderItem::hasCatalogColumns()) {
                return response()->json(['message' => 'سفارش این کالا از روی میز هنوز فعال نیست.'], 422);
            }
            $guestRows[] = $row;
        }

        try {
            $prepared = $posSale->prepareLines($guestRows, $atelierId);
            $posSale->assertStock($prepared);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $shopTable = ShopTable::resolveFor(
            $atelierId,
            (int) $request->table_number,
            $request->input('kind')
        );

        DB::beginTransaction();
        try {
            ShopTable::query()->where('id', $shopTable->id)->lockForUpdate()->first();

            $existing = $this->activeOrderForTable($atelierId, (int) $shopTable->id, true);
            if ($existing && $existing->isOnlinePaymentExpired()) {
                $existing->markCancelled(TableOrder::CANCELLED_BY_SYSTEM);
                $existing = null;
            }
            if ($existing) {
                DB::rollBack();

                return response()->json([
                    'message' => $existing->isAwaitingOnlinePayment()
                        ? 'سفارش قبلی این میز منتظر پرداخت آنلاین است. آن را پرداخت یا لغو کنید.'
                        : 'این میز یک سفارش فعال دارد. نمی‌توان سفارش جدید ثبت کرد.',
                    'code' => 'table_has_active_order',
                    'table_order' => $existing->toPublicArray(),
                ], 409);
            }

            $totalAmount = 0.0;
            foreach ($prepared as $line) {
                $totalAmount += (float) $line['sale_price'] * (float) $line['quantity'];
            }

            $order = TableOrder::create([
                'atelier_id' => $atelierId,
                'shop_table_id' => $shopTable->id,
                'table_label' => $shopTable->display_name,
                'phone' => $request->input('phone'),
                'note' => $request->note,
                'total_amount' => \App\Tools\PriceTools::roundToman($totalAmount),
                'use_credit' => $request->boolean('use_credit'),
                'payment_method' => $paymentMethod,
                'status' => TableOrder::STATUS_PENDING,
            ]);

            if ($this->requestHasReceipt($request)) {
                $this->saveReceiptToOrder($order, $request);
            }

            $withCatalog = TableOrderItem::hasCatalogColumns();
            foreach ($prepared as $line) {
                $attrs = [
                    'table_order_id' => $order->id,
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                    'purchase_price' => $line['purchase_price'],
                    'sale_price' => $line['sale_price'],
                    'size' => $line['size'],
                    'color' => $line['color'],
                ];
                if ($withCatalog) {
                    $attrs['produced_good_id'] = $line['produced_good_id'];
                    $attrs['raw_material_id'] = $line['raw_material_id'];
                    $attrs['item_name'] = $line['item_name'];
                }
                TableOrderItem::create($attrs);
            }

            DB::commit();
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

        $credit = 0.0;
        $phone = $order->phone;
        if ($phone) {
            $credit = (float) (UserShiksho::where('phone', $phone)
                ->where('atelier_id', $atelierId)
                ->value('credit') ?? 0);
        }

        $payload = [
            'message' => 'سفارش ثبت شد و منتظر پرداخت است',
            'table' => $shopTable,
            'credit' => $credit,
            'payment_methods' => TableOrder::paymentMethodsForApi(),
        ];

        if ($paymentMethod === TableOrder::METHOD_ONLINE) {
            $payload = array_merge($payload, $this->startOnlinePayment($order, $request, $checkout, $gateway));
        }

        $payload['table_order'] = $order->fresh(['items.product', 'shopTable'])->toPublicArray();

        return response()->json($payload, 201);
    }

    /**
     * پرداخت مجدد آنلاین سفارش پای میز توسط مشتری (بدون لاگین)
     * POST /api/{shop}/table-order/{tableOrder}/pay-online
     */
    public function guestPayOnline(
        Request $request,
        $shop,
        TableOrder $tableOrder,
        TableOrderCheckoutService $checkout,
        GatewayPaymentService $gateway
    ) {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $this->assertGuestTableOrder($tableOrder, $atelierId);
        Setting::setShopContext($atelierId);

        $request->validate([
            'return_url' => 'nullable|string|max:1000',
        ]);

        if ($response = $this->assertGuestPhoneMatches($request, $tableOrder)) {
            return $response;
        }

        if ($tableOrder->isPaidOnline() || $tableOrder->status === TableOrder::STATUS_PAID) {
            return response()->json([
                'message' => 'این سفارش قبلاً پرداخت شده است.',
                'table_order' => $tableOrder->fresh(['items.product', 'shopTable'])->toPublicArray(),
            ], 409);
        }

        if (! $tableOrder->isPending()) {
            return response()->json(['message' => 'این سفارش لغو شده است.'], 422);
        }

        if (! TableOrder::isPaymentMethodEnabled(TableOrder::METHOD_ONLINE)) {
            return response()->json(['message' => 'پرداخت آنلاین برای این فروشگاه فعال نیست.'], 422);
        }

        if ($tableOrder->payment_method !== TableOrder::METHOD_ONLINE) {
            $tableOrder->update(['payment_method' => TableOrder::METHOD_ONLINE]);
        }

        $result = $this->startOnlinePayment($tableOrder->fresh(), $request, $checkout, $gateway);
        $status = isset($result['payment_error']) ? 422 : 200;

        return response()->json(array_merge($result, [
            'message' => $result['payment_error'] ?? 'در حال انتقال به درگاه پرداخت',
            'table_order' => $tableOrder->fresh(['items.product', 'shopTable'])->toPublicArray(),
        ]), $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function startOnlinePayment(
        TableOrder $order,
        Request $request,
        TableOrderCheckoutService $checkout,
        GatewayPaymentService $gateway
    ): array {
        if ($order->payableAmountToman() <= 0) {
            // اعتبار مشتری کل مبلغ را پوشش می‌دهد؛ نیازی به درگاه نیست.
            try {
                $checkout->pay($order, Request::create('/', 'POST', ['payment_settlement' => 'card']));

                return ['paid_with_credit' => true];
            } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                $data = json_decode((string) $e->getResponse()->getContent(), true);

                return ['payment_error' => is_array($data) && ! empty($data['message']) ? $data['message'] : 'خطا در ثبت سفارش'];
            }
        }

        try {
            $payment = $gateway->start(
                (int) $order->atelier_id,
                null,
                GatewayPayment::TYPE_TABLE_ORDER,
                (int) $order->id,
                $request->input('return_url'),
                $order->phone ?: null
            );
        } catch (\Throwable $e) {
            report($e);

            return ['payment_error' => $e instanceof \RuntimeException
                ? $e->getMessage()
                : 'اتصال به درگاه پرداخت برقرار نشد. دوباره تلاش کنید.'];
        }

        if (TableOrder::hasOnlineColumns()) {
            $order->gateway_payment_id = $payment['id'] ?? null;
        }
        $order->touch();

        return [
            'payment_url' => $payment['payment_url'] ?? null,
            'payment' => $payment,
        ];
    }

    /**
     * لیست سفارش‌های پای میز (برای پرسنل فروشگاه)
     * GET /api/table-orders?status=pending
     */
    public function index(Request $request)
    {
        $this->requireStaffShopUser($request);
        $atelierId = $this->assertShopFeature($request, \App\Services\ShopFeatureFlags::RESTAURANT_CAFE, 'سفارش حضوری برای این فروشگاه فعال نیست.');

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
        if ($status === TableOrder::STATUS_PENDING) {
            $query->visibleToStaff();
        }
        if ($status === TableOrder::STATUS_CANCELLED && TableOrder::hasOnlineColumns()) {
            // سفارش آنلاینِ رهاشده (لغو خودکار) برای پرسنل نمایش داده نمی‌شود.
            $query->where(function ($q) {
                $q->whereNull('cancelled_by')->orWhere('cancelled_by', '!=', TableOrder::CANCELLED_BY_SYSTEM);
            });
        }
        if ($request->boolean('paid_online') && TableOrder::hasOnlineColumns()) {
            $query->whereNotNull('online_paid_at');
        }

        if ($request->filled('table_number')) {
            $query->whereHas('shopTable', function ($q) use ($request) {
                $q->where('table_number', $request->table_number);
                if ($request->filled('kind')) {
                    $q->where('kind', ShopTable::normalizeKind($request->input('kind')));
                }
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
        $atelierId = $this->assertShopFeature($request, \App\Services\ShopFeatureFlags::RESTAURANT_CAFE, 'سفارش حضوری برای این فروشگاه فعال نیست.');

        $base = TableOrder::query()
            ->where('atelier_id', $atelierId)
            ->where('status', TableOrder::STATUS_PENDING)
            ->visibleToStaff();

        $count = (clone $base)->count();
        $withReceipt = (clone $base)->whereNotNull('receipt_path')->count();
        $latest = (clone $base)->with('shopTable')->orderByDesc('id')->first();

        // سفارش‌های آنلاین پرداخت‌شده (معمولاً خودکار فاکتور شده‌اند) هم باید به پرسنل اعلام شوند.
        $latestOnlinePaid = null;
        $onlinePaidToday = 0;
        if (TableOrder::hasOnlineColumns()) {
            $onlineBase = TableOrder::query()
                ->where('atelier_id', $atelierId)
                ->whereNotNull('online_paid_at')
                ->where('online_paid_at', '>=', now()->subHours(12));
            $onlinePaidToday = (clone $onlineBase)->count();
            $latestOnlinePaid = (clone $onlineBase)->with('shopTable')->orderByDesc('online_paid_at')->orderByDesc('id')->first();
        }

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
            'latest_label' => $latest
                ? ($latest->table_label ?: optional($latest->shopTable)->display_name)
                : null,
            'latest_online_paid_id' => $latestOnlinePaid ? (int) $latestOnlinePaid->id : null,
            'latest_online_paid_label' => $latestOnlinePaid
                ? ($latestOnlinePaid->table_label ?: optional($latestOnlinePaid->shopTable)->display_name)
                : null,
            'online_paid_recent' => $onlinePaidToday,
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

        $smsFields = \App\Exceptions\InsufficientShopSmsQuotaException::sideEffectFields(
            (bool) $purchase->getAttribute('sms_quota_exhausted'),
            $purchase->getAttribute('sms_sent') === true || (bool) $purchase->getAttribute('sms_quota_exhausted')
        );

        return response()->json(array_merge([
            'message' => 'پرداخت ثبت شد و فاکتور ساخته شد',
            'table_order' => $tableOrder->toPublicArray(),
            'purchase' => $purchase,
        ], $smsFields), 200);
    }

    /**
     * لغو سفارش پای میزِ پرداخت‌نشده توسط ادمین
     * POST /api/table-orders/{tableOrder}/cancel
     */
    public function cancel(Request $request, TableOrder $tableOrder)
    {
        $this->requireStaffShopUser($request);
        $this->assertModelBelongsToStaffAtelier($request, $tableOrder);

        return $this->cancelPendingOrder($tableOrder, TableOrder::CANCELLED_BY_STAFF);
    }

    /**
     * لغو سفارش پای میز توسط مشتری (بدون لاگین)
     * POST /api/{shop}/table-order/{tableOrder}/cancel
     */
    public function guestCancel(Request $request, $shop, TableOrder $tableOrder)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $this->assertGuestTableOrder($tableOrder, $atelierId);

        if ($response = $this->assertGuestPhoneMatches($request, $tableOrder)) {
            return $response;
        }

        return $this->cancelPendingOrder($tableOrder, TableOrder::CANCELLED_BY_CUSTOMER);
    }

    /**
     * @return \Illuminate\Http\JsonResponse|null
     */
    private function assertGuestPhoneMatches(Request $request, TableOrder $tableOrder)
    {
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

        return null;
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

        $this->expireStaleOnlineOrders($atelierId);

        $query = TableOrder::query()
            ->where('atelier_id', $atelierId)
            ->where('status', TableOrder::STATUS_PENDING)
            ->whereNull('purchase_id')
            ->with(['items.product', 'shopTable'])
            ->orderByDesc('id');

        if ($request->filled('table_number')) {
            $query->whereHas('shopTable', function ($q) use ($request) {
                $q->where('table_number', $request->table_number)
                    ->where('kind', ShopTable::normalizeKind($request->input('kind', $request->query('kind'))));
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

        if ($tableOrder->status === TableOrder::STATUS_CANCELLED) {
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

        $kind = ShopTable::normalizeKind($request->query('kind'));
        $shopTable = ShopTable::where('atelier_id', $atelierId)
            ->where('table_number', $tableNumber)
            ->where('kind', $kind)
            ->where('is_active', true)
            ->first();

        // اگر با kind اشتباه باز شده (مثلاً اتاق با /reserv)، همان شماره را با kind واقعی پیدا کن
        if (! $shopTable) {
            $shopTable = ShopTable::where('atelier_id', $atelierId)
                ->where('table_number', $tableNumber)
                ->where('is_active', true)
                ->firstOrFail();
        }

        $atelier = Atelier::query()->find($atelierId);
        $restaurantCafeEnabled = Setting::isEnabled('restaurant_cafe_enabled', false);
        $roomServicesEnabled = Setting::isEnabled('room_services_enabled', false);

        $this->expireStaleOnlineOrders($atelierId, (int) $shopTable->id);

        $this->expireStaleOnlineOrders($atelierId, (int) $shopTable->id);

        $pending = TableOrder::where('shop_table_id', $shopTable->id)
            ->where('status', TableOrder::STATUS_PENDING)
            ->with(['items.product', 'shopTable'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (TableOrder $order) => $order->toPublicArray())
            ->values();

        return response()->json([
            'table' => $shopTable,
            'kind' => $shopTable->kind,
            'pending_orders' => $pending,
            'payment_methods' => TableOrder::paymentMethodsForApi(),
            'room_services_enabled' => $roomServicesEnabled,
            'restaurant_cafe_enabled' => $restaurantCafeEnabled,
            // منو برای میز و اتاق وقتی رستوران/کافه فعال باشد
            'allow_menu' => $restaurantCafeEnabled,
            'allow_services' => $roomServicesEnabled,
            'menu_theme' => \App\Services\ReservMenuTheme::forApi(),
            'shop' => [
                'id' => $atelier?->id,
                'name' => $atelier?->name,
                'code' => $atelier?->code,
            ],
            'shop_name' => $atelier?->name,
            'shop_code' => $atelier?->code,
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

        if ($tableOrder->isPaidOnline()) {
            return response()->json([
                'message' => 'این سفارش آنلاین پرداخت شده و قابل لغو نیست. فاکتور را بسازید و در صورت نیاز از بخش مرجوعی اقدام کنید.',
            ], 422);
        }

        if (! TableOrder::hasOnlineColumns()) {
            $tableOrder->load(['items.product', 'shopTable']);
            $payload = $tableOrder->toPublicArray();
            $payload['cancelled_by'] = $cancelledBy;

            $receiptPath = $tableOrder->receipt_path;
            $tableOrder->items()->delete();
            $tableOrder->delete();

            if ($receiptPath && Storage::exists('public/'.$receiptPath)) {
                Storage::delete('public/'.$receiptPath);
            }

            return response()->json([
                'message' => 'سفارش لغو و حذف شد',
                'cancelled_by' => $cancelledBy,
                'table_order' => $payload,
            ]);
        }

        $tableOrder->markCancelled($cancelledBy);

        return response()->json([
            'message' => 'سفارش لغو شد',
            'cancelled_by' => $cancelledBy,
            'table_order' => $tableOrder->fresh(['items.product', 'shopTable'])->toPublicArray(),
        ]);
    }

    /** سفارش‌های آنلاینِ رهاشده (پرداخت‌نشده پس از مهلت) لغو نرم می‌شوند تا میز آزاد شود. */
    private function expireStaleOnlineOrders(int $atelierId, ?int $shopTableId = null): void
    {
        if (! TableOrder::hasOnlineColumns()) {
            return;
        }

        TableOrder::query()
            ->where('atelier_id', $atelierId)
            ->when($shopTableId !== null, fn ($q) => $q->where('shop_table_id', $shopTableId))
            ->where('status', TableOrder::STATUS_PENDING)
            ->where('payment_method', TableOrder::METHOD_ONLINE)
            ->whereNotNull('gateway_payment_id')
            ->whereNull('online_paid_at')
            ->whereNull('purchase_id')
            ->where('updated_at', '<', now()->subMinutes(TableOrder::ONLINE_PAYMENT_TTL_MINUTES))
            ->update([
                'status' => TableOrder::STATUS_CANCELLED,
                'cancelled_by' => TableOrder::CANCELLED_BY_SYSTEM,
                'cancelled_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function activeOrderForTable(int $atelierId, int $shopTableId, bool $lock = false): ?TableOrder
    {
        $query = TableOrder::query()
            ->where('atelier_id', $atelierId)
            ->where('shop_table_id', $shopTableId)
            ->where('status', TableOrder::STATUS_PENDING)
            ->whereNull('purchase_id')
            ->orderByDesc('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $order = $query->first();
        if ($order) {
            $order->load(['items.product', 'shopTable']);
        }

        return $order;
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
