<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\PurchaseDebtPayment;
use App\Models\Product;
use App\Models\UserShiksho;
use App\Services\AccountingSalePoster;
use App\Tools\PhoneTools;
use App\Tools\PriceTools;
use App\Tools\ProductQuantityTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurchaseDebtController extends Controller
{
    /**
     * گرید بدهکاران: شماره تلفن، نام، تعداد قرض، مبلغ کل بدهی.
     */
    public function grid(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        $baseQuery = Purchase::query()
            ->forAtelier($atelierId)
            ->where('payment_type', 'debt')
            ->where('is_debt_settled', false)
            ->where('total_amount', '>', 0)
            ->whereNotNull('phone')
            ->where('phone', '!=', '');

        $searchDataModel = json_decode($request->input('searchFilterModel'));
        if ($searchDataModel) {
            if (is_object($searchDataModel) && isset($searchDataModel->phone)) {
                $baseQuery->where('phone', 'like', '%'.$searchDataModel->phone.'%');
            } elseif (is_object($searchDataModel) && isset($searchDataModel->name) && trim((string) $searchDataModel->name) !== '') {
                $namePhones = $this->phonesMatchingCustomerName($atelierId, trim((string) $searchDataModel->name));
                $baseQuery->whereIn('phone', $namePhones ?: ['__none__']);
            } elseif (is_string($searchDataModel) && $searchDataModel !== '') {
                $term = $searchDataModel;
                $namePhones = $this->phonesMatchingCustomerName($atelierId, $term);
                $baseQuery->where(function ($q) use ($term, $namePhones) {
                    $q->where('phone', 'like', '%'.$term.'%');
                    if ($namePhones !== []) {
                        $q->orWhereIn('phone', $namePhones);
                    }
                });
            }
        }

        if ($request->has('phone')) {
            $baseQuery->where('phone', 'like', '%'.$request->input('phone').'%');
        }

        $purchaseIds = (clone $baseQuery)->pluck('id')->all();

        if ($purchaseIds === []) {
            return response([
                'data' => [],
                'meta' => [
                    'total_debtors' => 0,
                    'total_debt_amount' => 0,
                    'total_debt_count' => 0,
                ],
            ], 200);
        }

        $with = ['purchasedProducts'];
        if (Schema::hasTable('purchase_debt_payments')) {
            $with[] = 'debtPayments';
        }

        $purchases = Purchase::with($with)
            ->whereIn('id', $purchaseIds)
            ->get();

        $grouped = [];
        foreach ($purchases as $purchase) {
            $phone = $purchase->phone;
            $amount = $purchase->outstandingDebtAmount();
            if ($amount <= 0) {
                continue;
            }

            if (! isset($grouped[$phone])) {
                $grouped[$phone] = [
                    'phone' => $phone,
                    'name' => null,
                    'debt_count' => 0,
                    'total_debt_amount' => 0.0,
                ];
            }

            $grouped[$phone]['debt_count']++;
            $grouped[$phone]['total_debt_amount'] += $amount;
        }

        $names = $this->customerNamesByPhone($atelierId, array_keys($grouped));
        foreach ($grouped as $phone => &$row) {
            $row['name'] = $names[$phone] ?? null;
        }
        unset($row);

        $rows = collect($grouped)
            ->map(function (array $row) {
                $row['total_debt_amount'] = round($row['total_debt_amount'], 2);

                return $row;
            })
            ->sortByDesc('total_debt_amount')
            ->values();

        $perPage = (int) $request->input('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;
        $page = max(1, (int) $request->input('page', 1));
        $total = $rows->count();
        $data = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        return response([
            'data' => $data,
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => (int) max(1, ceil($total / $perPage)),
            'meta' => [
                'total_debtors' => $total,
                'total_debt_amount' => round($rows->sum('total_debt_amount'), 2),
                'total_debt_count' => (int) $rows->sum('debt_count'),
            ],
        ], 200);
    }

    /**
     * لیست فاکتورهای قرضی یک مشتری (تسویه‌نشده و تسویه‌شده).
     */
    public function byPhone(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);

        if ($request->has('phone')) {
            $request->merge([
                'phone' => PhoneTools::normalizeIranPhone($request->input('phone')),
            ]);
        }

        $request->validate([
            'phone' => 'required|string|regex:/^09\d{9}$/',
            'status' => 'nullable|string|in:pending,settled,all',
        ]);

        $phone = $request->input('phone');
        $status = $request->input('status', 'all');

        $with = ['purchasedProducts.product'];
        if (Schema::hasTable('purchase_debt_payments')) {
            $with[] = 'debtPayments';
        }

        $query = Purchase::with($with)
            ->forAtelier($atelierId)
            ->where('payment_type', 'debt')
            ->where('phone', $phone)
            ->where('total_amount', '>', 0)
            ->orderByDesc('id');

        if ($status === 'pending') {
            $query->where('is_debt_settled', false);
        } elseif ($status === 'settled') {
            $query->where('is_debt_settled', true);
        }

        $names = $this->customerNamesByPhone($atelierId, [$phone]);
        $customerName = $names[$phone] ?? null;

        $purchases = $query->get()->map(fn (Purchase $p) => $this->formatDebtPurchase($p, $customerName));

        $pendingTotal = $purchases
            ->where('is_debt_settled', false)
            ->sum('payable_amount');

        return response([
            'phone' => $phone,
            'name' => $customerName,
            'customer_name' => $customerName,
            'debt_count' => $purchases->where('is_debt_settled', false)->count(),
            'total_debt_amount' => round((float) $pendingTotal, 2),
            'purchases' => $purchases,
        ], 200);
    }

    /**
     * جزئیات یک فاکتور قرضی.
     */
    public function show(Request $request, Purchase $purchase)
    {
        $this->assertModelBelongsToStaffAtelier($request, $purchase);

        if (! $purchase->isDebt()) {
            return response()->json(['message' => 'این فاکتور از نوع قرضی نیست.'], 422);
        }

        $with = ['purchasedProducts.product'];
        if (Schema::hasTable('purchase_debt_payments')) {
            $with[] = 'debtPayments';
        }
        $purchase->load($with);

        $names = $this->customerNamesByPhone((int) $purchase->atelier_id, array_filter([$purchase->phone]));

        return response($this->formatDebtPurchase($purchase, $names[$purchase->phone] ?? null), 200);
    }

    /**
     * تسویه فاکتور قرضی — مبلغ به دریافتی‌های روز تسویه اضافه می‌شود.
     * پرداخت جزئی مجاز است تا مانده صفر شود.
     */
    public function settle(Request $request, Purchase $purchase)
    {
        $this->requireStaffShopUser($request);
        $this->assertModelBelongsToStaffAtelier($request, $purchase);

        if (! $purchase->isDebt()) {
            return response()->json(['message' => 'این فاکتور از نوع قرضی نیست.'], 422);
        }

        if ($purchase->isDebtSettled()) {
            return response()->json(['message' => 'این فاکتور قبلاً تسویه شده است.'], 422);
        }

        $payable = $purchase->outstandingDebtAmount();
        if ($payable <= 0) {
            return response()->json(['message' => 'مبلغ قابل تسویه این فاکتور صفر است.'], 422);
        }

        $fields = $request->validate([
            'card_amount' => 'nullable|numeric|min:0',
            'cash_amount' => 'nullable|numeric|min:0',
            'amount' => 'nullable|numeric|min:0',
            'payment_settlement' => 'nullable|string|in:card,cash',
            'note' => 'nullable|string|max:500',
        ]);

        $card = PriceTools::roundToman((float) ($fields['card_amount'] ?? 0));
        $cash = PriceTools::roundToman((float) ($fields['cash_amount'] ?? 0));
        $requestedAmount = isset($fields['amount']) ? PriceTools::roundToman((float) $fields['amount']) : 0.0;
        $settlement = $fields['payment_settlement'] ?? null;

        if ($card <= 0 && $cash <= 0) {
            $pay = $requestedAmount > 0 ? $requestedAmount : $payable;
            if ($settlement === 'card') {
                $card = $pay;
            } else {
                $cash = $pay;
            }
        }

        $payNow = round($card + $cash, 2);
        if ($payNow < 1) {
            return response()->json(['message' => 'مبلغ پرداخت باید حداقل ۱ تومان باشد.'], 422);
        }

        if (($payNow - $payable) > 0.02) {
            return response()->json([
                'message' => 'مبلغ پرداخت از مانده بدهی بیشتر است.',
                'payable_amount' => $payable,
                'card_amount' => $card,
                'cash_amount' => $cash,
            ], 422);
        }

        $canPartial = Schema::hasTable('purchase_debt_payments');
        if (! $canPartial && abs($payNow - $payable) > 0.02) {
            return response()->json([
                'message' => 'جمع مبلغ کارت و نقد باید برابر مبلغ بدهی باشد.',
                'payable_amount' => $payable,
                'card_amount' => $card,
                'cash_amount' => $cash,
            ], 422);
        }

        $fullySettled = false;
        $remainingAfter = $payable;

        DB::transaction(function () use ($purchase, $card, $cash, $payNow, $fields, $canPartial, &$fullySettled, &$remainingAfter) {
            $locked = Purchase::query()->where('id', $purchase->id)->lockForUpdate()->first();
            if (! $locked || $locked->isDebtSettled()) {
                abort(response()->json(['message' => 'این فاکتور قبلاً تسویه شده است.'], 422));
            }

            if ($canPartial) {
                $locked->unsetRelation('debtPayments');
            }
            $remaining = $locked->outstandingDebtAmount();
            if ($remaining <= 0) {
                abort(response()->json(['message' => 'مبلغ قابل تسویه این فاکتور صفر است.'], 422));
            }
            if (($payNow - $remaining) > 0.02) {
                abort(response()->json([
                    'message' => 'مبلغ پرداخت از مانده بدهی بیشتر است.',
                    'payable_amount' => $remaining,
                ], 422));
            }

            $remainingAfter = round($remaining - $payNow, 2);
            $fullySettled = $remainingAfter <= 0.02;

            if ($canPartial) {
                $payment = PurchaseDebtPayment::create([
                    'purchase_id' => $locked->id,
                    'card_amount' => $card,
                    'cash_amount' => $cash,
                    'note' => $fields['note'] ?? null,
                    'paid_at' => now(),
                ]);

                $locked->update([
                    'is_debt_settled' => $fullySettled,
                    'debt_settled_at' => $fullySettled ? now() : $locked->debt_settled_at,
                    'debt_settled_card_amount' => round((float) $locked->debt_settled_card_amount + $card, 2),
                    'debt_settled_cash_amount' => round((float) $locked->debt_settled_cash_amount + $cash, 2),
                    'debt_settlement_note' => $fields['note'] ?? $locked->debt_settlement_note,
                ]);
                AccountingSalePoster::postDebtPayment($payment->fresh());
            } else {
                $locked->update([
                    'is_debt_settled' => true,
                    'debt_settled_at' => now(),
                    'debt_settled_card_amount' => $card,
                    'debt_settled_cash_amount' => $cash,
                    'debt_settlement_note' => $fields['note'] ?? null,
                ]);
                AccountingSalePoster::postDebtSettle($locked->fresh());
                $fullySettled = true;
                $remainingAfter = 0.0;
            }
        });

        $with = ['purchasedProducts.product'];
        if ($canPartial) {
            $with[] = 'debtPayments';
        }
        $purchase->refresh()->load($with);
        $names = $this->customerNamesByPhone((int) $purchase->atelier_id, array_filter([$purchase->phone]));

        $message = $fullySettled
            ? 'فاکتور با موفقیت تسویه شد.'
            : 'پرداخت ثبت شد. مانده بدهی '.number_format($remainingAfter).' تومان است.';

        return response([
            'message' => $message,
            'remaining_amount' => round(max(0, $remainingAfter), 2),
            'is_debt_settled' => $fullySettled,
            'purchase' => $this->formatDebtPurchase($purchase, $names[$purchase->phone] ?? null),
        ], 200);
    }

    protected function formatDebtPurchase(Purchase $purchase, ?string $customerName = null): array
    {
        $purchase->loadMissing('purchasedProducts.product');
        if (Schema::hasTable('purchase_debt_payments')) {
            $purchase->loadMissing('debtPayments');
        }

        $paidAmount = $purchase->recordedDebtPaymentsAmount();
        $remaining = $purchase->outstandingDebtAmount();

        return [
            'id' => $purchase->id,
            'phone' => $purchase->phone,
            'name' => $customerName,
            'customer_name' => $customerName,
            'payment_type' => $purchase->payment_type,
            'payment_type_label' => 'قرضی',
            'total_amount' => (float) $purchase->total_amount,
            'discount_amount' => (float) $purchase->discount_amount,
            'credit_used' => (float) $purchase->credit_used,
            'payable_amount' => $remaining,
            'invoice_payable_amount' => $purchase->payableAmount(),
            'paid_amount' => $paidAmount,
            'remaining_amount' => $remaining,
            'cash_amount' => (float) $purchase->cash_amount,
            'card_amount' => (float) $purchase->card_amount,
            'is_debt_settled' => (bool) $purchase->is_debt_settled,
            'debt_settled_at' => $purchase->debt_settled_at,
            'debt_settled_card_amount' => (float) $purchase->debt_settled_card_amount,
            'debt_settled_cash_amount' => (float) $purchase->debt_settled_cash_amount,
            'debt_settlement_note' => $purchase->debt_settlement_note,
            'created_at' => $purchase->created_at,
            'debt_payments' => $this->formatDebtPayments($purchase),
            'products' => $purchase->purchasedProducts->map(function ($item) {
                $product = $item->product;

                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $product ? $product->name : null,
                    'quantity' => (float) $item->quantity,
                    'unit_type' => $product?->unit_type ?? Product::UNIT_PIECE,
                    'unit_label' => ProductQuantityTools::unitLabel($product?->unit_type),
                    'sale_price' => (float) $item->sale_price,
                    'purchase_price' => (float) $item->purchase_price,
                    'line_total' => round((float) $item->sale_price * (float) $item->quantity, 2),
                    'size' => $item->size,
                    'color' => $item->color,
                ];
            })->values(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function formatDebtPayments(Purchase $purchase): array
    {
        if (! Schema::hasTable('purchase_debt_payments')) {
            return [];
        }

        $purchase->loadMissing('debtPayments');

        return $purchase->debtPayments->map(function (PurchaseDebtPayment $payment) {
            return [
                'id' => $payment->id,
                'card_amount' => (float) $payment->card_amount,
                'cash_amount' => (float) $payment->cash_amount,
                'amount' => (float) $payment->amount,
                'note' => $payment->note,
                'paid_at' => $payment->paid_at_jalali,
            ];
        })->values()->all();
    }

    /**
     * @param  list<string>  $phones
     * @return array<string, string>
     */
    protected function customerNamesByPhone(int $atelierId, array $phones): array
    {
        $phones = array_values(array_unique(array_filter($phones, fn ($p) => is_string($p) && $p !== '')));
        if ($phones === [] || ! Schema::hasTable('user_shiksho') || ! Schema::hasColumn('user_shiksho', 'name')) {
            return [];
        }

        return UserShiksho::query()
            ->where('atelier_id', $atelierId)
            ->whereIn('phone', $phones)
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->pluck('name', 'phone')
            ->all();
    }

    /**
     * @return list<string>
     */
    protected function phonesMatchingCustomerName(int $atelierId, string $name): array
    {
        if ($name === '' || ! Schema::hasTable('user_shiksho') || ! Schema::hasColumn('user_shiksho', 'name')) {
            return [];
        }

        return UserShiksho::query()
            ->where('atelier_id', $atelierId)
            ->where('name', 'like', '%'.$name.'%')
            ->pluck('phone')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
