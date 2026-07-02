<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Tools\PhoneTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            } elseif (is_string($searchDataModel) && $searchDataModel !== '') {
                $baseQuery->where('phone', 'like', '%'.$searchDataModel.'%');
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

        $purchases = Purchase::with('purchasedProducts')
            ->whereIn('id', $purchaseIds)
            ->get();

        $grouped = [];
        foreach ($purchases as $purchase) {
            $phone = $purchase->phone;
            $amount = $purchase->payableAmount();
            if ($amount <= 0) {
                continue;
            }

            if (! isset($grouped[$phone])) {
                $grouped[$phone] = [
                    'phone' => $phone,
                    'debt_count' => 0,
                    'total_debt_amount' => 0.0,
                ];
            }

            $grouped[$phone]['debt_count']++;
            $grouped[$phone]['total_debt_amount'] += $amount;
        }

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

        $query = Purchase::with(['purchasedProducts.product'])
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

        $purchases = $query->get()->map(fn (Purchase $p) => $this->formatDebtPurchase($p));

        $pendingTotal = $purchases
            ->where('is_debt_settled', false)
            ->sum('payable_amount');

        return response([
            'phone' => $phone,
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

        $purchase->load('purchasedProducts.product');

        return response($this->formatDebtPurchase($purchase), 200);
    }

    /**
     * تسویه فاکتور قرضی — مبلغ به دریافتی‌های روز تسویه اضافه می‌شود.
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

        $payable = $purchase->payableAmount();
        if ($payable <= 0) {
            return response()->json(['message' => 'مبلغ قابل تسویه این فاکتور صفر است.'], 422);
        }

        $fields = $request->validate([
            'card_amount' => 'nullable|numeric|min:0',
            'cash_amount' => 'nullable|numeric|min:0',
            'payment_settlement' => 'nullable|string|in:card,cash',
            'note' => 'nullable|string|max:500',
        ]);

        $card = (float) ($fields['card_amount'] ?? 0);
        $cash = (float) ($fields['cash_amount'] ?? 0);
        $settlement = $fields['payment_settlement'] ?? null;

        if ($card <= 0 && $cash <= 0 && $settlement === 'card') {
            $card = $payable;
        } elseif ($card <= 0 && $cash <= 0 && $settlement === 'cash') {
            $cash = $payable;
        } elseif ($card <= 0 && $cash <= 0) {
            $cash = $payable;
        }

        if (abs(($card + $cash) - $payable) > 0.02) {
            return response()->json([
                'message' => 'جمع مبلغ کارت و نقد باید برابر مبلغ بدهی باشد.',
                'payable_amount' => $payable,
                'card_amount' => $card,
                'cash_amount' => $cash,
            ], 422);
        }

        DB::transaction(function () use ($purchase, $card, $cash, $fields) {
            $locked = Purchase::query()->where('id', $purchase->id)->lockForUpdate()->first();
            if (! $locked || $locked->isDebtSettled()) {
                abort(response()->json(['message' => 'این فاکتور قبلاً تسویه شده است.'], 422));
            }

            $locked->update([
                'is_debt_settled' => true,
                'debt_settled_at' => now(),
                'debt_settled_card_amount' => round($card, 2),
                'debt_settled_cash_amount' => round($cash, 2),
                'debt_settlement_note' => $fields['note'] ?? null,
            ]);
        });

        $purchase->refresh()->load('purchasedProducts.product');

        return response([
            'message' => 'فاکتور با موفقیت تسویه شد.',
            'purchase' => $this->formatDebtPurchase($purchase),
        ], 200);
    }

    protected function formatDebtPurchase(Purchase $purchase): array
    {
        $purchase->loadMissing('purchasedProducts.product');

        return [
            'id' => $purchase->id,
            'phone' => $purchase->phone,
            'payment_type' => $purchase->payment_type,
            'payment_type_label' => 'قرضی',
            'total_amount' => (float) $purchase->total_amount,
            'discount_amount' => (float) $purchase->discount_amount,
            'credit_used' => (float) $purchase->credit_used,
            'payable_amount' => $purchase->payableAmount(),
            'is_debt_settled' => (bool) $purchase->is_debt_settled,
            'debt_settled_at' => $purchase->debt_settled_at,
            'debt_settled_card_amount' => (float) $purchase->debt_settled_card_amount,
            'debt_settled_cash_amount' => (float) $purchase->debt_settled_cash_amount,
            'debt_settlement_note' => $purchase->debt_settlement_note,
            'created_at' => $purchase->created_at,
            'products' => $purchase->purchasedProducts->map(function ($item) {
                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product ? $item->product->name : null,
                    'quantity' => (int) $item->quantity,
                    'sale_price' => (float) $item->sale_price,
                    'purchase_price' => (float) $item->purchase_price,
                    'line_total' => round((float) $item->sale_price * (int) $item->quantity, 2),
                    'size' => $item->size,
                    'color' => $item->color,
                ];
            })->values(),
        ];
    }
}
